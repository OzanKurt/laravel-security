<?php

namespace OzanKurt\Shield\Services\Scanner;

use OzanKurt\Shield\Models\Lookups\ScannerFindingStatus;
use OzanKurt\Shield\Models\Lookups\ScannerTarget;
use OzanKurt\Shield\Models\ScannerFinding;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use RuntimeException;

/**
 * Moves and restores files based on per-target quarantine policy.
 *
 * Policy is one of:
 *   'move_and_stub' , moves the file to the quarantine dir, writes an empty stub at the
 *                       original path so subsequent scans see "the file" exists but has no
 *                       executable content. Reversible via restore().
 *   'log_only'       , never touches the file. Used for app_files / vendor where mutation
 *                       would break the running app.
 *
 * Storage layout:
 *   storage/shield/quarantine/<finding-uuid>.bin      , the original file contents
 *   storage/shield/quarantine/<finding-uuid>.json     , sidecar metadata (original path, mtime, perms)
 */
class Quarantine
{
    public function __construct(
        private LookupResolver $lookups,
        private AuditLogger $audit,
    ) {}

    /**
     * Quarantine a finding if its target policy permits.
     */
    public function move(ScannerFinding $finding): bool
    {
        $targetName = $this->lookups->name(ScannerTarget::class, $finding->target_id);
        $policy = $this->policyFor($targetName);

        if ($policy !== 'move_and_stub') {
            return false;
        }

        if (empty($finding->file_path) || ! is_file($finding->file_path)) {
            return false;
        }

        $quarantineDir = $this->quarantineDir();
        if (! is_dir($quarantineDir)) {
            @mkdir($quarantineDir, 0700, true);
        }

        $vaultPath = $quarantineDir . DIRECTORY_SEPARATOR . $finding->uuid . '.bin';
        $sidecarPath = $quarantineDir . DIRECTORY_SEPARATOR . $finding->uuid . '.json';

        $originalPath = $finding->file_path;
        $stat = @stat($originalPath);

        if ($stat === false || ! @rename($originalPath, $vaultPath)) {
            throw new RuntimeException("Could not move {$originalPath} to {$vaultPath}");
        }

        @file_put_contents($sidecarPath, json_encode([
            'finding_uuid' => $finding->uuid,
            'original_path' => $originalPath,
            'mtime' => $stat['mtime'] ?? null,
            'mode' => $stat['mode'] ?? null,
            'size' => $stat['size'] ?? null,
            'quarantined_at' => date('c'),
        ], JSON_PRETTY_PRINT));

        @file_put_contents($originalPath, '');
        if (isset($stat['mode'])) {
            @chmod($originalPath, $stat['mode'] & 0777);
        }

        $finding->quarantine_path = $vaultPath;
        $finding->status_id = $this->lookups->id(ScannerFindingStatus::class, 'quarantined');
        $finding->save();

        $this->audit->log('scanner.quarantine', "Quarantined {$originalPath}", [
            'severity' => 'high',
            'subject_type' => ScannerFinding::class,
            'subject_id' => $finding->id,
            'meta' => [
                'original_path' => $originalPath,
                'vault_path' => $vaultPath,
                'target' => $targetName,
            ],
        ]);

        return true;
    }

    /**
     * Restore a previously quarantined file back to its original location.
     */
    public function restore(string $findingUuid): bool
    {
        $finding = ScannerFinding::query()->where('uuid', $findingUuid)->first();
        if (! $finding) {
            throw new RuntimeException("Finding {$findingUuid} not found");
        }

        if (empty($finding->quarantine_path) || ! is_file($finding->quarantine_path)) {
            throw new RuntimeException("Quarantine vault file missing for {$findingUuid}");
        }

        $sidecarPath = preg_replace('/\.bin$/', '.json', $finding->quarantine_path);
        $originalPath = $finding->file_path;

        if (is_file($sidecarPath)) {
            $sidecar = json_decode((string) @file_get_contents($sidecarPath), true) ?: [];
            if (! empty($sidecar['original_path'])) {
                $originalPath = $sidecar['original_path'];
            }
        }

        if (! $originalPath) {
            throw new RuntimeException("Cannot determine original path for {$findingUuid}");
        }

        $parent = dirname($originalPath);
        if (! is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        if (is_file($originalPath)) {
            @unlink($originalPath);
        }

        if (! @rename($finding->quarantine_path, $originalPath)) {
            throw new RuntimeException("Could not restore {$finding->quarantine_path} to {$originalPath}");
        }

        if (! empty($sidecar['mode']) && is_int($sidecar['mode'])) {
            @chmod($originalPath, $sidecar['mode'] & 0777);
        }
        if (! empty($sidecar['mtime']) && is_int($sidecar['mtime'])) {
            @touch($originalPath, $sidecar['mtime']);
        }

        @unlink($sidecarPath);

        $finding->quarantine_path = null;
        $finding->status_id = $this->lookups->id(ScannerFindingStatus::class, 'open');
        $finding->save();

        $this->audit->log('scanner.quarantine', "Restored {$originalPath}", [
            'severity' => 'medium',
            'subject_type' => ScannerFinding::class,
            'subject_id' => $finding->id,
            'meta' => ['restored_to' => $originalPath, 'action' => 'restore'],
        ]);

        return true;
    }

    private function policyFor(?string $targetName): string
    {
        if (! $targetName) {
            return 'log_only';
        }

        return (string) config(
            "shield.scanner.quarantine.per_target.{$targetName}",
            'log_only',
        );
    }

    private function quarantineDir(): string
    {
        $configured = (string) config('shield.scanner.quarantine.path', 'storage/shield/quarantine');

        if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $configured)) {
            return $configured;
        }

        return base_path($configured);
    }
}

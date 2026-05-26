<?php

namespace OzanKurt\Shield\Services\Scanner;

use OzanKurt\Shield\Models\ScannerFinding;
use OzanKurt\Shield\Models\ScannerRun;
use OzanKurt\Shield\Models\Lookups\ScannerBackend as ScannerBackendLookup;
use OzanKurt\Shield\Models\Lookups\ScannerFindingStatus;
use OzanKurt\Shield\Models\Lookups\ScannerStatus;
use OzanKurt\Shield\Models\Lookups\ScannerTarget;
use OzanKurt\Shield\Models\Lookups\ScannerTrigger;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Support\CorrelationId;

class Scanner
{
    /** @param ScannerBackendInterface[] $backends */
    public function __construct(
        private array $backends,
        private LookupResolver $lookups,
        private CorrelationId $correlation,
    ) {}

    /**
     * Execute a scan run.
     *
     * @param  string[]  $targetNames   Names from ls_scanner_targets
     * @param  string[]  $backendNames  Names from ls_scanner_backends (empty = all available)
     * @param  string    $triggerName   Name from ls_scanner_triggers
     */
    public function run(array $targetNames, array $backendNames, string $triggerName = 'manual'): ScannerRun
    {
        $triggerId = $this->lookups->id(ScannerTrigger::class, $triggerName)
            ?? throw new \InvalidArgumentException("Unknown trigger: $triggerName");
        $runningId = $this->lookups->id(ScannerStatus::class, 'running')
            ?? throw new \RuntimeException('Status "running" not seeded');
        $completedId = $this->lookups->id(ScannerStatus::class, 'completed')
            ?? throw new \RuntimeException('Status "completed" not seeded');
        $openStatusId = $this->lookups->id(ScannerFindingStatus::class, 'open')
            ?? throw new \RuntimeException('Finding status "open" not seeded');

        $scannerRun = ScannerRun::create([
            'correlation_id' => $this->correlation->get(),
            'trigger_id' => $triggerId,
            'status_id' => $runningId,
            'targets' => $targetNames,
            'backends' => $backendNames,
            'started_at' => now(),
        ]);

        try {
            $activeBackends = $this->resolveBackends($backendNames);
            $allFindings = [];
            $filesScanned = 0;

            foreach ($activeBackends as $backend) {
                if ($backend->isPerFile()) {
                    $files = $this->resolveTargetFiles($targetNames);
                    foreach ($files as $filePath) {
                        $filesScanned++;
                        $fileFindings = $backend->scanFile($filePath);
                        foreach ($fileFindings as $finding) {
                            $finding['file_path'] = $filePath;
                            $finding['backend_name'] = $backend->name();
                            $allFindings[] = $finding;
                        }
                    }
                } else {
                    $runFindings = $backend->scanRun();
                    foreach ($runFindings as $finding) {
                        $finding['backend_name'] = $backend->name();
                        $allFindings[] = $finding;
                    }
                }
            }

            // Dedupe by file_path + signature_id/signature_ref + backend
            $allFindings = $this->deduplicateFindings($allFindings);

            $criticalCount = 0;
            foreach ($allFindings as $finding) {
                $severityId = $this->lookups->id(LogLevel::class, $finding['severity'] ?? 'medium')
                    ?? $this->lookups->id(LogLevel::class, 'medium');
                $targetId = $this->resolveFirstMatchingTargetId($targetNames);
                $backendId = $this->lookups->id(ScannerBackendLookup::class, $finding['backend_name'] ?? 'native');

                ScannerFinding::create([
                    'correlation_id' => $this->correlation->get(),
                    'scanner_run_id' => $scannerRun->id,
                    'target_id' => $targetId,
                    'backend_id' => $backendId,
                    'signature_id' => $finding['signature_id'] ?? null,
                    'signature_ref' => $finding['signature_ref'] ?? null,
                    'severity_id' => $severityId,
                    'status_id' => $openStatusId,
                    'file_path' => $finding['file_path'],
                    'file_hash' => isset($finding['file_path']) && is_file($finding['file_path'])
                        ? hash_file('sha256', $finding['file_path'])
                        : null,
                    'line_number' => $finding['line_number'] ?? null,
                    'matched_content' => $finding['matched_content'] ?? null,
                ]);

                if (($finding['severity'] ?? '') === 'critical') {
                    $criticalCount++;
                }
            }

            $scannerRun->update([
                'status_id' => $completedId,
                'finished_at' => now(),
                'files_scanned' => $filesScanned,
                'findings_count' => count($allFindings),
                'findings_critical_count' => $criticalCount,
            ]);
        } catch (\Throwable $e) {
            $failedId = $this->lookups->id(ScannerStatus::class, 'failed');
            $scannerRun->update([
                'status_id' => $failedId,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $scannerRun->fresh();
    }

    public function cancel(ScannerRun $run): void
    {
        $cancelledId = $this->lookups->id(ScannerStatus::class, 'cancelled')
            ?? throw new \RuntimeException('Status "cancelled" not seeded');

        $run->update([
            'status_id' => $cancelledId,
            'finished_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return ScannerBackendInterface[] */
    private function resolveBackends(array $names): array
    {
        if (empty($names)) {
            return array_filter($this->backends, fn ($b) => $b->isAvailable());
        }

        return array_values(array_filter(
            $this->backends,
            fn ($b) => in_array($b->name(), $names, true) && $b->isAvailable()
        ));
    }

    /** @return string[] */
    private function resolveTargetFiles(array $targetNames): array
    {
        $files = [];

        foreach ($targetNames as $targetName) {
            $paths = $this->targetPaths($targetName);
            foreach ($paths as $path) {
                if (is_file($path)) {
                    $files[] = $path;
                } elseif (is_dir($path)) {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $files[] = $file->getRealPath();
                        }
                    }
                }
            }
        }

        return array_unique($files);
    }

    private function targetPaths(string $targetName): array
    {
        $configuredPaths = config("shield.scanner.targets.{$targetName}.paths");
        if ($configuredPaths !== null) {
            return (array) $configuredPaths;
        }

        // Built-in defaults
        return match ($targetName) {
            'vendor' => [base_path('vendor')],
            'app_files' => [app_path(), base_path('routes'), base_path('config')],
            'public_uploads' => [public_path('uploads'), storage_path('app/public')],
            'recently_modified' => $this->recentlyModifiedPaths(),
            'config_drift' => [base_path('config')],
            'env_audit' => [base_path('.env'), base_path('.env.production')],
            'dotfiles' => [base_path()],
            'db_content' => [],
            'unknown_files' => [base_path()],
            default => [],
        };
    }

    private function recentlyModifiedPaths(): array
    {
        $hours = config('shield.scanner.targets.recently_modified.hours', 24);
        $cutoff = time() - ($hours * 3600);
        $searchRoot = base_path();
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($searchRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getMTime() >= $cutoff) {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    private function resolveFirstMatchingTargetId(array $targetNames): int
    {
        foreach ($targetNames as $name) {
            $id = $this->lookups->id(ScannerTarget::class, $name);
            if ($id !== null) {
                return $id;
            }
        }

        // Fallback: unknown_files
        return $this->lookups->id(ScannerTarget::class, 'unknown_files')
            ?? throw new \RuntimeException('No valid target ID found');
    }

    private function deduplicateFindings(array $findings): array
    {
        $seen = [];
        $unique = [];

        foreach ($findings as $finding) {
            $key = ($finding['file_path'] ?? '')
                . '|' . ($finding['signature_id'] ?? '')
                . '|' . ($finding['signature_ref'] ?? '')
                . '|' . ($finding['backend_name'] ?? '');

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $finding;
            }
        }

        return $unique;
    }
}

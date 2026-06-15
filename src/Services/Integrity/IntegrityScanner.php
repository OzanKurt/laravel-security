<?php

namespace OzanKurt\Shield\Services\Integrity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use OzanKurt\Shield\Events\IntegrityScanCompletedEvent;
use OzanKurt\Shield\Models\IntegrityBaseline;
use OzanKurt\Shield\Models\IntegrityChange;
use OzanKurt\Shield\Models\IntegrityRun;
use OzanKurt\Shield\Models\Lookups\IntegrityChangeType;
use OzanKurt\Shield\Models\Lookups\IntegrityComparisonBasis;
use OzanKurt\Shield\Models\Lookups\IntegrityStatus;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\ScannerTrigger;
use Throwable;

/**
 * Orchestrates one file-integrity scan: lock, load + verify the known-good
 * baseline, build the current manifest, diff vs known-good and vs the previous
 * run (hybrid), classify + score changes, persist the run + change rows, and
 * fire IntegrityScanCompletedEvent.
 *
 * Secure by default: a missing baseline establishes a PROVISIONAL one (never
 * silently trusted); a tampered baseline is a security signal that does NOT
 * auto-rebless.
 */
class IntegrityScanner
{
    private const SEVERITY_ORDER = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function run(string $diskName = 'app', string $triggerName = 'manual'): IntegrityRun
    {
        $spec = config("shield.integrity.disks.{$diskName}");
        if (! is_array($spec)) {
            throw new InvalidArgumentException("Unknown integrity disk [{$diskName}].");
        }

        $maxRuntime = (int) config('shield.integrity.limits.max_runtime', 3600);
        $lock = Cache::lock("shield:integrity:{$diskName}", $maxRuntime);

        if (! $lock->get()) {
            return $this->persistRun($diskName, $triggerName, 'skipped');
        }

        try {
            return $this->execute($diskName, $triggerName, $spec);
        } finally {
            $lock->release();
        }
    }

    private function execute(string $diskName, string $triggerName, array $spec): IntegrityRun
    {
        $algo = (string) config('shield.integrity.hash_algo', 'sha256');
        $keyBase = $spec['key_base'] ?? base_path();

        $manifestSpec = [
            'roots' => $this->resolveRoots($spec),
            'key_base' => $keyBase,
            'include' => $spec['include'] ?? ['**/*'],
            'exclude' => $spec['exclude'] ?? [],
            'follow_symlinks' => $spec['follow_symlinks'] ?? false,
            'max_file_size' => $spec['max_file_size'] ?? 50 * 1024 * 1024,
            'hash_algo' => $algo,
            'max_files' => (int) config('shield.integrity.limits.max_files', 500000),
            'max_iterations' => (int) config('shield.integrity.limits.max_iterations', 1000000),
        ];

        $scopeFingerprint = hash('sha256', json_encode([
            'include' => $manifestSpec['include'],
            'exclude' => $manifestSpec['exclude'],
            'follow_symlinks' => $manifestSpec['follow_symlinks'],
            'max_file_size' => $manifestSpec['max_file_size'],
            'algo' => $algo,
            'disk' => $diskName,
        ]));

        $baseline = new Baseline($this->resolveKey(), $algo);
        $knownGoodPath = $this->artifactPath($diskName, 'baseline');
        $lastRunPath = $this->artifactPath($diskName, 'last-run');

        // Load + verify the known-good baseline. Tamper and corruption are
        // distinct, and neither auto-rebuilds the baseline.
        try {
            $knownGood = $baseline->read($knownGoodPath);
        } catch (BaselineTamperException $e) {
            return $this->persistRun($diskName, $triggerName, 'tamper_suspected', 'critical', $e->getMessage());
        } catch (BaselineCorruptException $e) {
            return $this->persistRun($diskName, $triggerName, 'failed', null, $e->getMessage());
        }

        $startedAt = now();

        try {
            $current = (new Manifest($manifestSpec))->build($knownGood['manifest'] ?? []);
        } catch (ManifestLimitException $e) {
            return $this->persistRun($diskName, $triggerName, 'aborted_limit', 'high', $e->getMessage(), $startedAt);
        }

        $counters = $this->countKinds($current);
        $rootHash = Manifest::rootHash($current, $algo);

        // First run: establish a PROVISIONAL baseline, do not trust it.
        if ($knownGood === null) {
            $meta = ['disk' => $diskName, 'scope_fingerprint' => $scopeFingerprint, 'root_hash' => $rootHash, 'files_total' => count($current)];
            $baseline->write($knownGoodPath, $current, $meta);
            $baseline->write($lastRunPath, $current, $meta);
            $this->upsertBaselineRow($diskName, $scopeFingerprint, $rootHash, $knownGoodPath, $algo, count($current), false);

            $run = $this->persistRun($diskName, $triggerName, 'baseline_established', null, null, $startedAt, [
                'scope_fingerprint' => $scopeFingerprint,
                'current_root_hash' => $rootHash,
            ] + $counters);

            IntegrityScanCompletedEvent::dispatch($run);

            return $run;
        }

        // Hybrid diff: delta vs the previous run, drift vs known-good.
        $scopeChanged = ($knownGood['meta']['scope_fingerprint'] ?? null) !== $scopeFingerprint;

        $lastRun = null;
        try {
            $lastRun = $baseline->read($lastRunPath);
        } catch (Throwable) {
            $lastRun = null;
        }

        $delta = Manifest::diff($lastRun['manifest'] ?? $knownGood['manifest'], $current);
        $drift = Manifest::diff($knownGood['manifest'], $current);

        $run = $this->persistRun($diskName, $triggerName, 'running', null, null, $startedAt, [
            'scope_fingerprint' => $scopeFingerprint,
            'baseline_root_hash' => $knownGood['meta']['root_hash'] ?? null,
            'current_root_hash' => $rootHash,
        ] + $counters);

        try {
            $evaluator = new SeverityRuleEvaluator(
                config('shield.integrity.severity_rules', []),
                ['public_docroot' => $this->publicDocroot($keyBase)]
            );
            $maps = $this->lookupMaps();
            $maxChanges = (int) config('shield.integrity.limits.max_persisted_changes_per_run', 5000);

            // When there is no previous run, the delta was computed against the
            // known-good manifest, so it is identical to the drift. Persist it once
            // (as last_run) to avoid duplicate rows.
            $bases = ['last_run' => $delta];
            if ($lastRun !== null) {
                $bases['known_good'] = $drift;
            }

            $persisted = 0;
            $maxSeverity = 'low';

            foreach ($bases as $basis => $diff) {
                foreach (['new', 'modified', 'deleted'] as $bucket) {
                    foreach ($diff[$bucket] as $path => $entry) {
                        if ($persisted >= $maxChanges) {
                            break 3;
                        }

                        $changeType = $this->classify($bucket, $entry, $scopeChanged);
                        $severity = $evaluator->evaluate($path, $changeType);
                        $maxSeverity = $this->maxSeverity($maxSeverity, $severity);

                        IntegrityChange::create([
                            'integrity_run_id' => $run->id,
                            'change_type_id' => $maps['change_type'][$changeType] ?? $maps['change_type']['modified'],
                            'compared_to_id' => $maps['comparison'][$basis],
                            'severity_id' => $maps['severity'][$severity] ?? null,
                            'path' => mb_substr($path, 0, 1024),
                            'old_hash' => $bucket === 'deleted' ? ($entry['sha256'] ?? null) : null,
                            'new_hash' => $bucket === 'deleted' ? null : ($entry['sha256'] ?? null),
                            'size_bytes' => $entry['size'] ?? null,
                            'file_mtime' => empty($entry['mtime']) ? null : Carbon::createFromTimestamp($entry['mtime']),
                            'symlink_target' => $entry['target'] ?? null,
                        ]);

                        $persisted++;
                    }
                }
            }

            $driftTotal = count($drift['new']) + count($drift['modified']) + count($drift['deleted']);

            $run->update([
                'status_id' => $this->statusId('completed'),
                'severity_id' => $maps['severity'][$maxSeverity] ?? null,
                'count_new' => count($delta['new']),
                'count_modified' => count($delta['modified']),
                'count_deleted' => count($delta['deleted']),
                'count_scope_changed' => $scopeChanged ? $driftTotal : 0,
                'count_vs_known_good' => $driftTotal,
                'finished_at' => now(),
                'duration_ms' => (int) now()->diffInMilliseconds($startedAt),
            ]);

            // The current state becomes the previous-run reference for next time.
            $baseline->write($lastRunPath, $current, [
                'disk' => $diskName, 'scope_fingerprint' => $scopeFingerprint, 'root_hash' => $rootHash, 'files_total' => count($current),
            ]);
        } catch (Throwable $e) {
            // Never leave the run stuck in 'running'.
            $run->update([
                'status_id' => $this->statusId('failed'),
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $run = $run->fresh();
        IntegrityScanCompletedEvent::dispatch($run);

        return $run;
    }

    /**
     * Promote the most recent run's state to the known-good baseline.
     */
    public function bless(string $diskName = 'app'): IntegrityBaseline
    {
        $spec = config("shield.integrity.disks.{$diskName}");
        if (! is_array($spec)) {
            throw new InvalidArgumentException("Unknown integrity disk [{$diskName}].");
        }

        $algo = (string) config('shield.integrity.hash_algo', 'sha256');
        $baseline = new Baseline($this->resolveKey(), $algo);
        $lastRunPath = $this->artifactPath($diskName, 'last-run');
        $knownGoodPath = $this->artifactPath($diskName, 'baseline');

        $lastRun = $baseline->read($lastRunPath);
        if ($lastRun === null) {
            throw new InvalidArgumentException("No prior run to bless for disk [{$diskName}]. Run shield:integrity first.");
        }

        $rootHash = Manifest::rootHash($lastRun['manifest'], $algo);
        $meta = $lastRun['meta'];
        $meta['root_hash'] = $rootHash;
        $baseline->write($knownGoodPath, $lastRun['manifest'], $meta);

        return $this->upsertBaselineRow(
            $diskName,
            $meta['scope_fingerprint'] ?? null,
            $rootHash,
            $knownGoodPath,
            $algo,
            count($lastRun['manifest']),
            true
        );
    }

    private function classify(string $bucket, array $entry, bool $scopeChanged): string
    {
        if ($scopeChanged && in_array($bucket, ['new', 'deleted'], true)) {
            return 'scope_changed';
        }

        if ($bucket === 'new' && ($entry['kind'] ?? null) === 'symlink') {
            return 'symlink_new';
        }

        if ($bucket === 'modified' && ($entry['kind'] ?? null) === 'unreadable') {
            return 'became_unreadable';
        }

        return $bucket; // new | modified | deleted
    }

    private function maxSeverity(string $a, string $b): string
    {
        return (self::SEVERITY_ORDER[$b] ?? 1) > (self::SEVERITY_ORDER[$a] ?? 1) ? $b : $a;
    }

    /** @return array{files_total:int,files_hashed:int,files_size_only:int,files_unreadable:int,files_excluded:int} */
    private function countKinds(array $manifest): array
    {
        $hashed = $sizeOnly = $unreadable = 0;
        foreach ($manifest as $e) {
            match ($e['kind'] ?? 'hashed') {
                'size_only' => $sizeOnly++,
                'unreadable' => $unreadable++,
                default => $hashed++,
            };
        }

        return [
            'files_total' => count($manifest),
            'files_hashed' => $hashed,
            'files_size_only' => $sizeOnly,
            'files_unreadable' => $unreadable,
            'files_excluded' => 0,
        ];
    }

    /** @return string[] absolute roots */
    private function resolveRoots(array $spec): array
    {
        $roots = (array) ($spec['roots'] ?? [base_path()]);
        $resolved = [];
        foreach ($roots as $root) {
            $resolved[] = preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $root) ? $root : base_path($root);
        }

        return $resolved;
    }

    private function publicDocroot(string $keyBase): string
    {
        $public = str_replace('\\', '/', public_path());
        $base = rtrim(str_replace('\\', '/', $keyBase), '/');
        if (str_starts_with($public, $base . '/')) {
            return substr($public, strlen($base) + 1);
        }

        return 'public';
    }

    private function resolveKey(): string
    {
        $path = config('shield.integrity.baseline.hmac_key_path');
        if (is_string($path) && $path !== '' && is_readable($path)) {
            return trim((string) file_get_contents($path));
        }

        return (string) config('shield.integrity.baseline.hmac_key', '');
    }

    private function artifactPath(string $diskName, string $name): string
    {
        return storage_path("shield/integrity/{$diskName}/{$name}.ndjson.gz");
    }

    /**
     * Persist a run row. Used both for the happy path and for terminal states
     * (skipped / tamper_suspected / failed / aborted_limit) that exit early.
     *
     * @param  array<string,mixed>  $extra
     */
    private function persistRun(
        string $diskName,
        string $triggerName,
        string $statusName,
        ?string $severityName = null,
        ?string $errorMessage = null,
        $startedAt = null,
        array $extra = []
    ): IntegrityRun {
        return IntegrityRun::create(array_merge([
            'status_id' => $this->statusId($statusName),
            'trigger_id' => ScannerTrigger::where('name', $triggerName)->value('id') ?? ScannerTrigger::where('name', 'manual')->value('id'),
            'severity_id' => $severityName ? LogLevel::where('name', $severityName)->value('id') : null,
            'disk' => $diskName,
            'started_at' => $startedAt ?? now(),
            'finished_at' => now(),
            'error_message' => $errorMessage,
        ], $extra));
    }

    private function upsertBaselineRow(
        string $diskName,
        ?string $scopeFingerprint,
        string $rootHash,
        string $artifactPath,
        string $algo,
        int $filesTotal,
        bool $signed
    ): IntegrityBaseline {
        IntegrityBaseline::where('disk', $diskName)->delete();

        return IntegrityBaseline::create([
            'disk' => $diskName,
            'scope_fingerprint' => $scopeFingerprint,
            'root_hash' => $rootHash,
            'artifact_path' => $artifactPath,
            'algo' => $algo,
            'files_total' => $filesTotal,
            'signed' => $signed,
            'blessed_at' => now(),
        ]);
    }

    private function statusId(string $name): int
    {
        return (int) IntegrityStatus::where('name', $name)->value('id');
    }

    /** @return array{change_type:array<string,int>,comparison:array<string,int>,severity:array<string,int>} */
    private function lookupMaps(): array
    {
        return [
            'change_type' => IntegrityChangeType::pluck('id', 'name')->all(),
            'comparison' => IntegrityComparisonBasis::pluck('id', 'name')->all(),
            'severity' => LogLevel::pluck('id', 'name')->all(),
        ];
    }
}

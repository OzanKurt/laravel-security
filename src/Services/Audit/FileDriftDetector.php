<?php

namespace OzanKurt\Shield\Services\Audit;

use Illuminate\Support\Facades\File;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

/**
 * Detects file-level drift by comparing current SHA-256 hashes against
 * a stored baseline.
 *
 * Behaviour:
 *   - First run: writes baseline, no audit entries emitted (establishes baseline).
 *   - Subsequent runs: compares hashes; for each changed/added/removed file,
 *     emits an AuditLog entry with the appropriate kind (file.drift /
 *     config.drift / composer.changed / env.changed).  Then updates the
 *     baseline so only NEW drift triggers on the next run.
 *
 * Config:  config('shield.audit.drift')
 */
class FileDriftDetector
{
    public function __construct(
        private AuditLogger $logger,
        private LookupResolver $lookups,
    ) {}

    /**
     * Run the drift detection cycle.
     *
     * @return array<int, array{path: string, kind: string, status: string}> findings
     */
    public function detect(): array
    {
        if (! config('shield.audit.drift.enabled', true)) {
            return [];
        }

        $baselinePath = $this->resolveBaselinePath();
        $current = $this->collectHashes();

        if (! File::exists($baselinePath)) {
            $this->writeBaseline($baselinePath, $current);
            return [];
        }

        $baseline = json_decode(File::get($baselinePath), true) ?? [];
        $findings = $this->compare($baseline, $current);

        foreach ($findings as $finding) {
            $this->emitAuditEntry($finding);
        }

        // Always update the baseline after comparison
        $this->writeBaseline($baselinePath, $current);

        return $findings;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>  path => sha256
     */
    private function collectHashes(): array
    {
        $paths = config('shield.audit.drift.paths', $this->defaultPaths());
        $hashes = [];
        $root = base_path();

        foreach ($paths as $pathKey => $glob) {
            // Support both absolute paths (e.g. in tests) and relative paths
            $isAbsolute = str_starts_with($pathKey, '/') || (strlen($pathKey) > 1 && $pathKey[1] === ':');
            $fullPath = $isAbsolute ? $pathKey : ($root . DIRECTORY_SEPARATOR . ltrim($pathKey, '/\\'));

            if ($glob === null) {
                // Single file
                if (File::isFile($fullPath)) {
                    // Store a canonical relative key (or absolute if outside base_path)
                    $storeKey = $isAbsolute ? $pathKey : ltrim(str_replace('\\', '/', str_replace($root, '', $fullPath)), '/');
                    $hashes[$storeKey] = hash_file('sha256', $fullPath);
                }
            } else {
                // Directory + glob pattern
                if (File::isDirectory($fullPath)) {
                    $files = File::glob($fullPath . DIRECTORY_SEPARATOR . $glob);
                    foreach ($files as $file) {
                        if ($isAbsolute) {
                            $storeKey = $file;
                        } else {
                            $storeKey = ltrim(str_replace('\\', '/', str_replace($root, '', $file)), '/');
                        }
                        $hashes[$storeKey] = hash_file('sha256', $file);
                    }
                }
            }
        }

        return $hashes;
    }

    /**
     * @param  array<string, string>  $baseline
     * @param  array<string, string>  $current
     * @return array<int, array{path: string, kind: string, status: string}>
     */
    private function compare(array $baseline, array $current): array
    {
        $findings = [];

        // Changed or new files
        foreach ($current as $path => $hash) {
            if (! isset($baseline[$path])) {
                $findings[] = ['path' => $path, 'kind' => $this->kindForPath($path), 'status' => 'added'];
            } elseif ($baseline[$path] !== $hash) {
                $findings[] = ['path' => $path, 'kind' => $this->kindForPath($path), 'status' => 'changed'];
            }
        }

        // Removed files
        foreach ($baseline as $path => $hash) {
            if (! isset($current[$path])) {
                $findings[] = ['path' => $path, 'kind' => $this->kindForPath($path), 'status' => 'removed'];
            }
        }

        return $findings;
    }

    private function kindForPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (str_starts_with($path, '.env')) {
            return '.env.changed';
        }

        if ($path === 'composer.json' || $path === 'composer.lock') {
            return 'composer.changed';
        }

        if (str_starts_with($path, 'config/')) {
            return 'config.drift';
        }

        return 'file.drift';
    }

    private function emitAuditEntry(array $finding): void
    {
        $kindName = $finding['kind'];

        // Ensure the kind row exists (some may not be in the seeder yet)
        if ($this->lookups->id(AuditLogKind::class, $kindName) === null) {
            $label = ucwords(str_replace(['.', '_'], ' ', $kindName));
            AuditLogKind::firstOrCreate(
                ['name' => $kindName],
                ['label' => $label, 'sort_order' => 0]
            );
            $this->lookups->flush(AuditLogKind::class);
        }

        $this->logger->log(
            $kindName,
            'File drift detected: ' . $finding['path'] . ' (' . $finding['status'] . ')',
            [
                'subject_type' => 'file',
                'subject_id'   => $finding['path'],
                'meta'         => ['status' => $finding['status'], 'path' => $finding['path']],
                'severity'     => 'high',
                'actor_type'   => 'system',
                'actor_id'     => null,
            ]
        );
    }

    private function writeBaseline(string $path, array $hashes): void
    {
        $dir = dirname($path);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function resolveBaselinePath(): string
    {
        $configured = config('shield.audit.drift.baseline_path', 'storage/shield/baselines/files.json');

        // Accept both absolute paths and relative-to-base_path
        if (str_starts_with($configured, '/') || (strlen($configured) > 1 && $configured[1] === ':')) {
            return $configured;
        }

        return base_path($configured);
    }

    private function defaultPaths(): array
    {
        return [
            'config/'       => '*.php',
            '.env'          => null,
            'composer.json' => null,
            'composer.lock' => null,
        ];
    }
}

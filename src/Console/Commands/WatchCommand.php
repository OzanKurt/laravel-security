<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Events\FileChangeDetectedEvent;
use OzanKurt\Shield\Services\Audit\AuditLogger;

/**
 * Long-running file change watcher.
 *
 * Prefers spatie/file-system-watcher (chokidar-backed, near-real-time).
 * Falls back to a polling loop when spatie is unavailable, suitable
 * for shared hosts without node.js.
 *
 * Operational note: run under supervisor / systemd. See docs/security-watch.md.
 */
class WatchCommand extends Command
{
    protected $signature = 'shield:watch {--once : Run a single polling pass then exit (useful for tests)}';
    protected $description = 'Long-running watcher that detects file changes and audit-logs them.';

    /** @var array<string, string> path => sha256 */
    private array $baseline = [];

    public function handle(AuditLogger $audit): int
    {
        if (! config('shield.scanner.watch.enabled', false)) {
            $this->warn('shield.scanner.watch.enabled is false. Set LS_WATCH_ENABLED=true to enable.');
            return self::SUCCESS;
        }

        $paths = $this->resolvePaths();

        if (empty($paths)) {
            $this->warn('No watch paths configured. Set shield.scanner.watch.paths.');
            return self::SUCCESS;
        }

        if ($this->canUseSpatieWatcher() && ! $this->option('once')) {
            return $this->runWithSpatie($paths, $audit);
        }

        return $this->runWithPolling($paths, $audit, (bool) $this->option('once'));
    }

    private function canUseSpatieWatcher(): bool
    {
        return class_exists(\Spatie\Watcher\Watch::class);
    }

    private function runWithSpatie(array $paths, AuditLogger $audit): int
    {
        $this->info('Watching with spatie/file-system-watcher (Ctrl+C to stop)');

        \Spatie\Watcher\Watch::paths(...$paths)
            ->onFileCreated(fn (string $path) => $this->handleEvent($audit, $path, 'created'))
            ->onFileUpdated(fn (string $path) => $this->handleEvent($audit, $path, 'updated'))
            ->onFileDeleted(fn (string $path) => $this->handleEvent($audit, $path, 'deleted'))
            ->start();

        return self::SUCCESS;
    }

    private function runWithPolling(array $paths, AuditLogger $audit, bool $once): int
    {
        $intervalMs = (int) config('shield.scanner.watch.poll_interval_ms', 3000);
        $this->info('Watching via polling (interval: ' . $intervalMs . 'ms)' . ($once ? ' [single pass]' : ''));

        $this->bootstrapBaseline($paths);

        do {
            $current = $this->scanPaths($paths);

            foreach ($current as $path => $hash) {
                if (! isset($this->baseline[$path])) {
                    $this->handleEvent($audit, $path, 'created', null, $hash);
                    $this->baseline[$path] = $hash;
                } elseif ($this->baseline[$path] !== $hash) {
                    $this->handleEvent($audit, $path, 'updated', $this->baseline[$path], $hash);
                    $this->baseline[$path] = $hash;
                }
            }

            foreach (array_diff_key($this->baseline, $current) as $path => $hash) {
                $this->handleEvent($audit, $path, 'deleted', $hash);
                unset($this->baseline[$path]);
            }

            if ($once) {
                break;
            }

            usleep($intervalMs * 1000);
        } while (true);

        return self::SUCCESS;
    }

    private function bootstrapBaseline(array $paths): void
    {
        $this->baseline = $this->scanPaths($paths);
        $this->info('Baseline established: ' . count($this->baseline) . ' files tracked.');
    }

    /** @return array<string, string> */
    private function scanPaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            foreach ($this->iterateFiles($path) as $file) {
                $hash = @hash_file('sha256', $file);
                if ($hash !== false) {
                    $out[$file] = $hash;
                }
            }
        }
        return $out;
    }

    private function iterateFiles(string $path): iterable
    {
        if (is_file($path)) {
            yield $path;
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    private function handleEvent(AuditLogger $audit, string $path, string $type, ?string $hashBefore = null, ?string $hashAfter = null): void
    {
        $this->line("[{$type}] {$path}");

        $audit->log('file.drift', "Watch detected {$type}: {$path}", [
            'severity' => 'medium',
            'meta' => [
                'change_type' => $type,
                'hash_before' => $hashBefore,
                'hash_after' => $hashAfter,
                'source' => 'shield:watch',
            ],
        ]);

        FileChangeDetectedEvent::dispatch($path, $type, $hashBefore, $hashAfter);
    }

    private function resolvePaths(): array
    {
        $configured = (array) config('shield.scanner.watch.paths', []);

        if (empty($configured)) {
            $configured = ['app/', 'config/', 'routes/', '.env'];
        }

        $resolved = [];
        foreach ($configured as $entry) {
            if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $entry)) {
                $resolved[] = $entry;
            } else {
                $candidate = base_path($entry);
                if (file_exists($candidate)) {
                    $resolved[] = $candidate;
                }
            }
        }

        return $resolved;
    }
}

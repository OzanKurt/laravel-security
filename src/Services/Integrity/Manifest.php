<?php

namespace OzanKurt\Shield\Services\Integrity;

/**
 * Builds and diffs file-integrity manifests for a local filesystem scope.
 *
 * A manifest maps a canonical disk-relative POSIX path to an entry:
 *   ['sha256' => ?string, 'size' => int, 'mtime' => int, 'kind' => string, 'target' => ?string]
 *
 * kind is one of: hashed | size_only | symlink | unreadable.
 *
 * Phase 1 targets the local filesystem (hash_file already streams, so memory
 * stays flat). Remote disks (sftp/s3) with readStream hashing are Phase 2.
 *
 * Pure and DB-free so it can be unit tested in isolation. It is also the shared
 * hasher the integrity engine uses.
 */
class Manifest
{
    /** @var string[] absolute roots to walk */
    private array $roots;
    private string $keyBase;
    /** @var string[] */
    private array $include;
    /** @var string[] */
    private array $exclude;
    private bool $followSymlinks;
    private int $maxFileSize;
    private string $algo;
    /** @var string[] lowercase extensions always hashed regardless of size */
    private array $alwaysHashExtensions;
    private int $maxFiles;
    private int $maxIterations;

    private int $fileCount = 0;
    private int $iterations = 0;
    /** @var array<string,bool> realpath dedup for symlink-loop protection */
    private array $seenDirs = [];

    public function __construct(array $spec)
    {
        $this->roots = array_map([$this, 'normalize'], (array) ($spec['roots'] ?? []));
        $this->keyBase = $this->normalize($spec['key_base'] ?? (defined('ABSPATH') ? ABSPATH : ''));
        $this->include = (array) ($spec['include'] ?? ['**/*']);
        $this->exclude = (array) ($spec['exclude'] ?? []);
        $this->followSymlinks = (bool) ($spec['follow_symlinks'] ?? false);
        $this->maxFileSize = (int) ($spec['max_file_size'] ?? 50 * 1024 * 1024);
        $this->algo = (string) ($spec['hash_algo'] ?? 'sha256');
        $this->alwaysHashExtensions = array_map('strtolower', (array) ($spec['always_hash_extensions'] ?? [
            'php', 'phtml', 'phar', 'phps', 'inc', 'pht',
        ]));
        $this->maxFiles = (int) ($spec['max_files'] ?? 500000);
        $this->maxIterations = (int) ($spec['max_iterations'] ?? 1000000);
    }

    /**
     * Build the manifest. When $previous is supplied, files whose size and mtime
     * are unchanged carry their prior hash forward instead of being re-hashed
     * (incremental rescan, the key scale win at large file counts).
     *
     * @param  array<string,array>  $previous
     * @return array<string,array> sorted by path
     *
     * @throws ManifestLimitException
     */
    public function build(array $previous = []): array
    {
        $this->fileCount = 0;
        $this->iterations = 0;
        $this->seenDirs = [];

        $manifest = [];

        foreach ($this->roots as $root) {
            if (is_file($root)) {
                $this->record($manifest, $root, $previous);
            } elseif (is_dir($root)) {
                $this->scanDir($manifest, $root, $previous);
            }
        }

        ksort($manifest);

        return $manifest;
    }

    private function scanDir(array &$manifest, string $dir, array $previous): void
    {
        $real = realpath($dir);
        if ($real !== false) {
            if (isset($this->seenDirs[$real])) {
                return; // recursive symlink guard
            }
            $this->seenDirs[$real] = true;
        }

        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->normalize($dir . '/' . $entry);

            if (++$this->iterations >= $this->maxIterations) {
                closedir($handle);
                throw new ManifestLimitException("Manifest exceeded max_iterations ({$this->maxIterations}).");
            }

            $key = $this->key($path);

            if (is_link($path)) {
                if ($this->excluded($key)) {
                    continue;
                }
                if (! $this->followSymlinks) {
                    if ($this->included($key)) {
                        $lst = @lstat($path);
                        $this->add($manifest, $key, [
                            'sha256' => null,
                            'size' => 0,
                            'mtime' => is_array($lst) ? (int) $lst['mtime'] : 0,
                            'kind' => 'symlink',
                            'target' => @readlink($path) ?: null,
                        ]);
                    }
                    continue;
                }
            }

            if (is_dir($path)) {
                $this->scanDir($manifest, $path, $previous);
                continue;
            }

            if (is_file($path)) {
                $this->record($manifest, $path, $previous);
            }
        }

        closedir($handle);
    }

    private function record(array &$manifest, string $path, array $previous): void
    {
        $key = $this->key($path);

        if (! $this->included($key) || $this->excluded($key)) {
            return;
        }

        $stat = @stat($path);
        if ($stat === false) {
            $this->add($manifest, $key, ['sha256' => null, 'size' => 0, 'mtime' => 0, 'kind' => 'unreadable', 'target' => null]);
            return;
        }

        $size = (int) $stat['size'];
        $mtime = (int) $stat['mtime'];

        // Incremental: carry the prior hash when size+mtime are unchanged.
        $prior = $previous[$key] ?? null;
        if ($prior !== null && ($prior['kind'] ?? null) === 'hashed'
            && (int) ($prior['size'] ?? -1) === $size
            && (int) ($prior['mtime'] ?? -1) === $mtime
            && ! empty($prior['sha256'])) {
            $this->add($manifest, $key, ['sha256' => $prior['sha256'], 'size' => $size, 'mtime' => $mtime, 'kind' => 'hashed', 'target' => null]);
            return;
        }

        // Oversized files are recorded by size+mtime only, EXCEPT script
        // extensions which are always hashed (anti size/mtime-backdating evasion).
        if ($size > $this->maxFileSize && ! $this->isAlwaysHashed($key)) {
            $this->add($manifest, $key, ['sha256' => null, 'size' => $size, 'mtime' => $mtime, 'kind' => 'size_only', 'target' => null]);
            return;
        }

        if (! is_readable($path)) {
            $this->add($manifest, $key, ['sha256' => null, 'size' => $size, 'mtime' => $mtime, 'kind' => 'unreadable', 'target' => null]);
            return;
        }

        $hash = @hash_file($this->algo, $path);
        if ($hash === false) {
            $this->add($manifest, $key, ['sha256' => null, 'size' => $size, 'mtime' => $mtime, 'kind' => 'unreadable', 'target' => null]);
            return;
        }

        $this->add($manifest, $key, ['sha256' => $hash, 'size' => $size, 'mtime' => $mtime, 'kind' => 'hashed', 'target' => null]);
    }

    private function add(array &$manifest, string $key, array $entry): void
    {
        $manifest[$key] = $entry;
        if (++$this->fileCount > $this->maxFiles) {
            throw new ManifestLimitException("Manifest exceeded max_files ({$this->maxFiles}).");
        }
    }

    /**
     * Diff two manifests. Returns ['new'=>[], 'modified'=>[], 'deleted'=>[]],
     * each a map of path => current (or prior, for deleted) entry.
     *
     * @param  array<string,array>  $base
     * @param  array<string,array>  $current
     * @return array{new:array,modified:array,deleted:array}
     */
    public static function diff(array $base, array $current): array
    {
        $new = [];
        $modified = [];
        $deleted = [];

        foreach ($current as $key => $entry) {
            if (! array_key_exists($key, $base)) {
                $new[$key] = $entry;
            } elseif (self::changed($base[$key], $entry)) {
                $modified[$key] = $entry;
            }
        }

        foreach ($base as $key => $entry) {
            if (! array_key_exists($key, $current)) {
                $deleted[$key] = $entry;
            }
        }

        return ['new' => $new, 'modified' => $modified, 'deleted' => $deleted];
    }

    private static function changed(array $a, array $b): bool
    {
        // Prefer hash comparison when both sides were hashed; otherwise fall
        // back to size+mtime (oversized/size_only) or kind transition.
        if (($a['kind'] ?? null) !== ($b['kind'] ?? null)) {
            return true;
        }

        if (! empty($a['sha256']) && ! empty($b['sha256'])) {
            return $a['sha256'] !== $b['sha256'];
        }

        if (($a['kind'] ?? null) === 'symlink') {
            return ($a['target'] ?? null) !== ($b['target'] ?? null);
        }

        return (int) ($a['size'] ?? -1) !== (int) ($b['size'] ?? -1)
            || (int) ($a['mtime'] ?? -1) !== (int) ($b['mtime'] ?? -1);
    }

    /**
     * Stable root hash over the sorted manifest, used to detect tamper and
     * for off-box attestation (Phase 3).
     *
     * @param  array<string,array>  $manifest
     */
    public static function rootHash(array $manifest, string $algo = 'sha256'): string
    {
        ksort($manifest);
        $ctx = hash_init($algo);
        foreach ($manifest as $key => $entry) {
            hash_update($ctx, $key . ':' . ($entry['sha256'] ?? '') . ':' . ($entry['size'] ?? '') . ':' . ($entry['kind'] ?? '') . "\n");
        }

        return hash_final($ctx);
    }

    /**
     * Flat path => sha256 view (hashed entries only).
     *
     * @param  array<string,array>  $manifest
     * @return array<string,string>
     */
    public static function hashes(array $manifest): array
    {
        $out = [];
        foreach ($manifest as $key => $entry) {
            if (! empty($entry['sha256'])) {
                $out[$key] = $entry['sha256'];
            }
        }

        return $out;
    }

    private function included(string $key): bool
    {
        return self::matchesAnyGlob($key, $this->include);
    }

    private function excluded(string $key): bool
    {
        return self::matchesAnyGlob($key, $this->exclude);
    }

    /**
     * True when $key matches any of the given `**`/`*`/`?` glob patterns.
     *
     * @param  string[]  $globs
     */
    public static function matchesAnyGlob(string $key, array $globs): bool
    {
        foreach ($globs as $pattern) {
            if (preg_match(self::globToRegex($pattern), $key)) {
                return true;
            }
        }

        return false;
    }

    private function isAlwaysHashed(string $key): bool
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->alwaysHashExtensions, true);
    }

    /** Canonical disk-relative POSIX key. */
    private function key(string $path): string
    {
        $path = $this->normalize($path);

        if ($this->keyBase !== '' && str_starts_with($path, $this->keyBase . '/')) {
            return substr($path, strlen($this->keyBase) + 1);
        }

        return ltrim($path, '/');
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function globToRegex(string $glob): string
    {
        $escaped = preg_quote($glob, '#');

        $regex = strtr($escaped, [
            '\*\*/' => '(?:.*/)?',
            '\*\*' => '.*',
            '\*' => '[^/]*',
            '\?' => '[^/]',
        ]);

        return '#^' . $regex . '$#';
    }
}

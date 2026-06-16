<?php

namespace OzanKurt\Shield\Services\Integrity;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reads and writes the pinned known-good baseline as a signed, atomically
 * written, gzipped NDJSON artifact.
 *
 * Artifact layout (before gzip): one header line then one line per file entry.
 * A sidecar `.sig` file holds the HMAC over the uncompressed NDJSON, with a
 * domain-separation prefix so a signature cannot be reused in another context.
 *
 * Honest threat model (see spec-005 section 4.2): a same-host attacker with the
 * key can re-sign, so the HMAC defends against unprivileged storage writes and
 * accidental corruption, not a full host compromise. Real attestation is Phase 3.
 */
class Baseline
{
    public const DOMAIN = 'shield.integrity.baseline.v1';

    /** A resolved key equal to this placeholder is rejected (fail closed). */
    public const PLACEHOLDER_KEY = 'please-set-LS_INTEGRITY_HMAC_KEY';

    public function __construct(
        private string $key,
        private string $algo = 'sha256'
    ) {
        self::assertUsableKey($key);
    }

    /**
     * Refuse to operate with an empty or placeholder signing key.
     *
     * @throws InvalidArgumentException
     */
    public static function assertUsableKey(string $key): void
    {
        if ($key === '' || $key === self::PLACEHOLDER_KEY) {
            throw new InvalidArgumentException(
                'Integrity baseline signing key is unset or still the placeholder. '
                . 'Set LS_INTEGRITY_HMAC_KEY (or integrity.baseline.hmac_key_path) to a real secret outside the scan root.'
            );
        }
    }

    /**
     * Atomically write a signed baseline artifact.
     *
     * @param  array<string,array>  $manifest
     * @param  array<string,mixed>  $meta
     */
    public function write(string $path, array $manifest, array $meta): void
    {
        $ndjson = $this->encode($manifest, $meta);
        $signature = $this->sign($ndjson);

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create baseline directory {$dir} (not writable?).");
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, gzencode($ndjson, 6)) === false
            || @file_put_contents($path . '.sig.tmp', $signature) === false) {
            throw new RuntimeException("Could not write baseline artifact under {$dir} (not writable?).");
        }

        // Rename is atomic on the same filesystem; the .sig flips first so a
        // reader never sees a new artifact paired with a stale signature.
        if (! @rename($path . '.sig.tmp', $path . '.sig') || ! @rename($tmp, $path)) {
            throw new RuntimeException("Could not finalize baseline artifact at {$path}.");
        }
    }

    /**
     * Read and verify a baseline artifact.
     *
     * @return array{manifest: array<string,array>, meta: array<string,mixed>}|null
     *                                          null when no artifact exists (first-run path)
     *
     * @throws BaselineCorruptException
     * @throws BaselineTamperException
     */
    public function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $gz = @file_get_contents($path);
        $ndjson = $gz === false ? false : @gzdecode($gz);
        if ($ndjson === false) {
            throw new BaselineCorruptException("Baseline artifact at {$path} could not be decompressed.");
        }

        if (! is_file($path . '.sig')) {
            throw new BaselineCorruptException("Baseline signature for {$path} is missing.");
        }

        $expected = (string) @file_get_contents($path . '.sig');
        if (! hash_equals($this->sign($ndjson), $expected)) {
            throw new BaselineTamperException("Baseline signature for {$path} does not verify.");
        }

        return $this->decode($ndjson, $path);
    }

    private function sign(string $ndjson): string
    {
        return hash_hmac($this->algo, self::DOMAIN . "\n" . $ndjson, $this->key);
    }

    /**
     * @param  array<string,array>  $manifest
     * @param  array<string,mixed>  $meta
     */
    private function encode(array $manifest, array $meta): string
    {
        ksort($manifest);

        $lines = [json_encode(['_meta' => $meta], JSON_UNESCAPED_SLASHES)];
        foreach ($manifest as $key => $e) {
            $lines[] = json_encode([
                'p' => $key,
                'h' => $e['sha256'] ?? null,
                's' => $e['size'] ?? 0,
                'm' => $e['mtime'] ?? 0,
                'k' => $e['kind'] ?? 'hashed',
                't' => $e['target'] ?? null,
            ], JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{manifest: array<string,array>, meta: array<string,mixed>}
     *
     * @throws BaselineCorruptException
     */
    private function decode(string $ndjson, string $path): array
    {
        $lines = explode("\n", trim($ndjson, "\n"));
        $header = json_decode(array_shift($lines) ?: '', true);
        if (! is_array($header) || ! array_key_exists('_meta', $header)) {
            throw new BaselineCorruptException("Baseline artifact at {$path} has an invalid header.");
        }

        $manifest = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['p'])) {
                throw new BaselineCorruptException("Baseline artifact at {$path} has a malformed entry.");
            }
            $manifest[$row['p']] = [
                'sha256' => $row['h'] ?? null,
                'size' => (int) ($row['s'] ?? 0),
                'mtime' => (int) ($row['m'] ?? 0),
                'kind' => $row['k'] ?? 'hashed',
                'target' => $row['t'] ?? null,
            ];
        }

        return ['manifest' => $manifest, 'meta' => $header['_meta']];
    }
}

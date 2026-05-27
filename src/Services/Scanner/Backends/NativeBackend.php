<?php

namespace OzanKurt\Shield\Services\Scanner\Backends;

use OzanKurt\Shield\Models\Signature;
use OzanKurt\Shield\Services\Scanner\ScannerBackendInterface;

class NativeBackend implements ScannerBackendInterface
{
    /** Max file size to scan in bytes (default 5MB) */
    private int $maxBytes;

    public function __construct()
    {
        $this->maxBytes = config('shield.scanner.native.max_file_bytes', 5 * 1024 * 1024);
    }

    public function name(): string
    {
        return 'native';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isPerFile(): bool
    {
        return true;
    }

    public function scanFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        if (filesize($path) > $this->maxBytes) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $signatures = Signature::query()->enabled()->get();
        $findings = [];

        foreach ($signatures as $signature) {
            $hit = false;

            switch ($signature->kind) {
                case 'regex':
                    $hit = @preg_match($signature->pattern, $content) === 1;
                    break;

                case 'file_hash':
                    $hit = hash('sha256', $content) === $signature->pattern;
                    break;

                case 'string_match':
                    $hit = stripos($content, $signature->pattern) !== false;
                    break;
            }

            if ($hit) {
                $findings[] = [
                    'signature_id' => $signature->id,
                    'signature_ref' => $signature->source_ref,
                    'severity' => $signature->severity?->name ?? 'medium',
                    'matched_content' => null,
                    'line_number' => null,
                ];
            }
        }

        return $findings;
    }

    public function scanRun(): array
    {
        throw new \LogicException('NativeBackend is a per-file backend; use scanFile() instead.');
    }
}

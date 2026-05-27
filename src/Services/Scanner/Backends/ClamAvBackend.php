<?php

namespace OzanKurt\Shield\Services\Scanner\Backends;

use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Scanner\ScannerBackendInterface;

class ClamAvBackend implements ScannerBackendInterface
{
    public function name(): string
    {
        return 'clamav';
    }

    public function isAvailable(): bool
    {
        if (! config('shield.scanner.clamav.enabled', false)) {
            return false;
        }

        if (! class_exists(\Xenolope\Quahog\Client::class)) {
            return false;
        }

        $socket = config('shield.scanner.clamav.socket', '/var/run/clamav/clamd.ctl');

        return file_exists($socket);
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

        $socket = config('shield.scanner.clamav.socket', '/var/run/clamav/clamd.ctl');
        $timeout = config('shield.scanner.clamav.timeout', 30);

        try {
            $socketResource = (new \Socket\Raw\Factory())->createClient('unix://' . $socket);
            $client = new \Xenolope\Quahog\Client($socketResource, $timeout, PHP_NORMAL_READ);

            $result = $client->scanStream(fopen($path, 'rb'));

            if ($result->isFound()) {
                return [[
                    'signature_id' => null,
                    'signature_ref' => $result->getReason(),
                    'severity' => 'critical',
                    'matched_content' => null,
                    'line_number' => null,
                ]];
            }

            return [];
        } catch (\Throwable $e) {
            try {
                app(AuditLogger::class)->log(
                    'scanner.completed',
                    'ClamAV scan error for ' . basename($path) . ': ' . $e->getMessage(),
                    ['severity' => 'medium', 'actor_type' => 'system', 'actor_id' => null]
                );
            } catch (\Throwable) {
                // Never let audit logging break a scan
            }

            return [];
        }
    }

    public function scanRun(): array
    {
        throw new \LogicException('ClamAvBackend is a per-file backend; use scanFile() instead.');
    }
}

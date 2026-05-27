<?php

namespace OzanKurt\Shield\Services\Scanner\Backends;

use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Scanner\ScannerBackendInterface;

class ComposerAuditBackend implements ScannerBackendInterface
{
    public function name(): string
    {
        return 'composer_audit';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isPerFile(): bool
    {
        return false;
    }

    public function scanFile(string $path): array
    {
        throw new \LogicException('ComposerAuditBackend is a run-level backend; use scanRun() instead.');
    }

    public function scanRun(): array
    {
        $output = [];
        $exitCode = 0;

        // exec is needed here: composer CLI with controlled (no user-supplied) arguments
        exec('composer audit --format=json --no-interaction 2>&1', $output, $exitCode);

        $json = implode('', $output);

        if (empty(trim($json))) {
            return [];
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->auditWarn('ComposerAuditBackend: failed to parse composer audit output: ' . $json);
            return [];
        }

        $advisories = $data['advisories'] ?? [];
        $findings = [];

        foreach ($advisories as $packageAdvisories) {
            if (! is_array($packageAdvisories)) {
                continue;
            }

            foreach ($packageAdvisories as $advisory) {
                $advisoryId = $advisory['advisoryId'] ?? $advisory['cve'] ?? uniqid('advisory-');
                $title = $advisory['title'] ?? 'Vulnerability';
                $severity = $this->mapCvssToLogLevel($advisory['severity'] ?? '');

                $findings[] = [
                    'file_path' => 'composer.lock',
                    'signature_id' => null,
                    'signature_ref' => $advisoryId,
                    'severity' => $severity,
                    'matched_content' => $title,
                    'line_number' => null,
                ];
            }
        }

        return $findings;
    }

    private function mapCvssToLogLevel(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical' => 'critical',
            'high' => 'high',
            'moderate', 'medium' => 'medium',
            default => 'low',
        };
    }

    private function auditWarn(string $message): void
    {
        try {
            app(AuditLogger::class)->log(
                'scanner.completed',
                $message,
                ['severity' => 'medium', 'actor_type' => 'system', 'actor_id' => null]
            );
        } catch (\Throwable) {
            // Never let audit logging break a scan
        }
    }
}

<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Scanner\Backends\ClamAvBackend;
use OzanKurt\Shield\Services\Scanner\Backends\NativeBackend;
use OzanKurt\Shield\Services\Scanner\ScannerBackendInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that streams every uploaded file through configured scanner backends
 * (Native + ClamAV when available) before the request handler sees it.
 *
 * On hit: rejects with 415 + audit log + optional notification dispatch.
 * Opt-in per route via `firewall.av_uploads`. Not in firewall.all group by default.
 */
class AvUploads
{
    public function __construct(
        private NativeBackend $native,
        private AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') && ! $request->isMethod('PUT')) {
            return $next($request);
        }

        $files = $this->collectFiles($request->allFiles());
        if (empty($files)) {
            return $next($request);
        }

        foreach ($files as $field => $file) {
            $findings = $this->scanFile($file);

            if (! empty($findings)) {
                $this->audit->log('scanner.finding', "Upload rejected by av_uploads middleware: {$field}", [
                    'severity' => 'critical',
                    'meta' => [
                        'field' => $field,
                        'filename' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'findings' => $findings,
                    ],
                ]);

                return response()->json([
                    'message' => 'Upload rejected: file contains suspicious content',
                    'field' => $field,
                    'filename' => $file->getClientOriginalName(),
                ], 415);
            }
        }

        return $next($request);
    }

    /**
     * Recursively flatten nested file inputs.
     *
     * @return array<string, UploadedFile>
     */
    private function collectFiles(array $files, string $prefix = ''): array
    {
        $flat = [];
        foreach ($files as $key => $value) {
            $fieldKey = $prefix === '' ? $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat = array_merge($flat, $this->collectFiles($value, $fieldKey));
            } elseif ($value instanceof UploadedFile) {
                $flat[$fieldKey] = $value;
            }
        }
        return $flat;
    }

    /** @return array<int, array<string, mixed>> */
    private function scanFile(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path || ! is_file($path)) {
            return [];
        }

        $findings = [];

        // Always run the native backend
        foreach ($this->native->scanFile($path) as $finding) {
            $findings[] = $finding + ['backend' => 'native'];
        }

        // ClamAV opt-in (composer suggest)
        if ($this->clamavBackend() && $this->clamavBackend()->isAvailable()) {
            foreach ($this->clamavBackend()->scanFile($path) as $finding) {
                $findings[] = $finding + ['backend' => 'clamav'];
            }
        }

        return $findings;
    }

    private function clamavBackend(): ?ScannerBackendInterface
    {
        if (! class_exists(ClamAvBackend::class) || ! class_exists(\Xenolope\Quahog\Client::class)) {
            return null;
        }
        try {
            return app(ClamAvBackend::class);
        } catch (\Throwable) {
            return null;
        }
    }
}

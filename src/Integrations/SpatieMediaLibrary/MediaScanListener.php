<?php

namespace OzanKurt\Shield\Integrations\SpatieMediaLibrary;

use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Scanner\Backends\ClamAvBackend;
use OzanKurt\Shield\Services\Scanner\Backends\NativeBackend;
use OzanKurt\Shield\Services\Scanner\ScannerBackendInterface;
use RuntimeException;

/**
 * Listens for Spatie Media Library's "media-saving" lifecycle hook and
 * blocks the save when scanner backends detect a malicious payload.
 *
 * Wired conditionally in ShieldServiceProvider::boot() only when
 * `\Spatie\MediaLibrary\Models\Media` exists in the host application.
 *
 * The actual event class moved across Media Library major versions
 * (FileAdder added throws to MediaCollection prior to v10; current v10/v11
 * exposes `media-saving` via spatie/laravel-medialibrary's `EnsureFileExists`
 * customization). The simplest cross-version hook is the Media model's
 * `saving` Eloquent event which fires before the row is persisted.
 *
 * Strategy: register this listener as a model observer at boot.
 */
class MediaScanListener
{
    public function __construct(
        private NativeBackend $native,
        private AuditLogger $audit,
    ) {}

    /**
     * Spatie's Media model 'saving' callback. Throws a RuntimeException
     * (caught by Media Library's pipeline) to abort the save.
     */
    public function saving(object $media): void
    {
        $path = $this->resolvePath($media);
        if (! $path || ! is_file($path)) {
            return;
        }

        $findings = $this->scan($path);

        if (! empty($findings)) {
            $this->audit->log('scanner.finding', "Spatie Media Library save blocked: {$path}", [
                'severity' => 'critical',
                'meta' => [
                    'media_id' => $media->id ?? null,
                    'collection' => $media->collection_name ?? null,
                    'filename' => $media->file_name ?? basename($path),
                    'findings' => $findings,
                ],
            ]);

            throw new RuntimeException('Shield: media file rejected, suspicious content detected.');
        }
    }

    /**
     * Spatie stores the source on the Media object as `getPath()` (resolved disk
     * path) or via a temporary file when uploading. Try a few accessors.
     */
    private function resolvePath(object $media): ?string
    {
        if (method_exists($media, 'getPath')) {
            $maybe = @$media->getPath();
            if (is_string($maybe) && is_file($maybe)) {
                return $maybe;
            }
        }

        if (isset($media->getOriginal()['path'])) {
            $maybe = $media->getOriginal()['path'];
            if (is_string($maybe) && is_file($maybe)) {
                return $maybe;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function scan(string $path): array
    {
        $findings = $this->native->scanFile($path);

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

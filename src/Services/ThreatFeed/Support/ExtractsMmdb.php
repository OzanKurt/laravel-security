<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Support;

/**
 * Shared tar.gz -> .mmdb extraction for the MaxMind providers (free GeoLite2
 * and premium GeoIP2). MaxMind ships each database as a gzipped tar whose inner
 * directory name carries a date, so we scan for the single *.mmdb member and
 * copy it to a stable filename.
 */
trait ExtractsMmdb
{
    protected function extractMmdb(string $tarGzPath, string $targetDir, string $expectedFilename): bool
    {
        $phar = new \PharData($tarGzPath);
        $phar->decompress(); // .tar
        $tarPath = preg_replace('/\.gz$/', '', $tarGzPath);
        $tar = new \PharData($tarPath);

        foreach ($tar as $file) {
            if (str_ends_with($file->getFilename(), '.mmdb')) {
                copy($file->getPathname(), $targetDir . '/' . $expectedFilename);
                @unlink($tarGzPath);
                @unlink($tarPath);
                return true;
            }
        }

        @unlink($tarGzPath);
        @unlink($tarPath);
        return false;
    }
}

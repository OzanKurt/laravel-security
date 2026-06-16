<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Routing\Controller;
use OzanKurt\Shield\Models\ScannerRun;
use OzanKurt\Shield\Models\Signature;
use OzanKurt\Shield\Services\Audit\EnvAuditor;

class DiagnosticsController extends Controller
{
    public function index(EnvAuditor $auditor)
    {
        $sysinfo = [
            ['PHP version', PHP_VERSION],
            ['Laravel version', app()->version()],
            ['Memory limit', ini_get('memory_limit')],
            ['Max execution time', ini_get('max_execution_time') . 's'],
            ['Queue connection', config('queue.default')],
            ['Cache driver', config('cache.default')],
            ['Session driver', config('session.driver')],
            ['DB connection', config('database.default')],
            ['Scheduler last run', $this->scheduledLastRun()],
            ['Last scanner run', $this->lastScannerRun()],
            ['Signatures total', Signature::count()],
            ['LS_AUDIT_HMAC_SECRET set', env('LS_AUDIT_HMAC_SECRET') ? 'Yes' : 'No (insecure)'],
            ['LS_BYPASS_KEY set', env('LS_BYPASS_KEY') ? 'Yes' : 'No'],
            ['LS_PREMIUM_LICENSE_KEY set', env('LS_PREMIUM_LICENSE_KEY') ? 'Yes' : 'No (free tier)'],
        ];

        $extensions = [
            ['xenolope/quahog (ClamAV)', class_exists(\Xenolope\Quahog\Client::class) ? 'installed' : 'not installed'],
            ['geoip2/geoip2 (Country/ASN)', class_exists(\GeoIp2\Database\Reader::class) ? 'installed' : 'not installed'],
            ['spatie/file-system-watcher (security:watch)', class_exists(\Spatie\Watcher\Watch::class) ? 'installed' : 'not installed'],
            ['spatie/laravel-medialibrary (auto-integration)', class_exists(\Spatie\MediaLibrary\MediaCollections\Models\Media::class) ? 'installed' : 'not installed'],
            ['predis/predis (cache+queue)', class_exists(\Predis\Client::class) ? 'installed' : 'not installed'],
            ['laravel/reverb (broadcasting)', class_exists(\Laravel\Reverb\Servers\Reverb\Server::class) ?? false ? 'installed' : 'not installed'],
        ];

        $envFindings = $auditor->audit();
        $envGrade = $auditor->grade();

        return view('shield::dashboard.diagnostics.index', compact('sysinfo', 'extensions', 'envFindings', 'envGrade'));
    }

    private function scheduledLastRun(): string
    {
        $log = storage_path('logs/laravel.log');
        if (! is_file($log)) return 'n/a';
        $mtime = @filemtime($log);
        return $mtime ? date('Y-m-d H:i:s', $mtime) : 'n/a';
    }

    private function lastScannerRun(): string
    {
        $run = ScannerRun::query()->latest('id')->first();
        return $run ? "#{$run->id} at {$run->started_at}" : 'never';
    }
}

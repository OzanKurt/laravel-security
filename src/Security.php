<?php

namespace OzanKurt\Security;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use OzanKurt\Security\Helpers\RecentlyModifiedFiles;
use voku\helper\AntiXSS;

class Security
{
    public AntiXSS $antiXss;

    public function __construct(AntiXSS $antiXss)
    {
        $this->antiXss = $antiXss;
    }

    public function route(string $route, array $parameters = [], bool $absolute = true)
    {
        return route(config('security.dashboard.route_name') . $route, $parameters, $absolute);
    }

    public function routeIsActive(string $route)
    {
        return request()->route()->getName() === config('security.dashboard.route_name') . $route;
    }

    public function getRecentlyModifiedFiles(int|Carbon $time_range = 604800, int $limit = 15, bool $resetCache = false): array
    {
        if ($time_range instanceof Carbon) {
            $time_range = $time_range->diffInSeconds(Carbon::now());
        }

        $cacheKey = 'recently_modified_files_' . $time_range . '_' . $limit;

        if ($resetCache) {
            cache()->forget($cacheKey);
        }

        $mostRecentFiles = cache()->remember($cacheKey, now()->addMinutes(5), function () use ($time_range, $limit) {
            $rmf = new RecentlyModifiedFiles(base_path(), $time_range);
            $rmf->run();
            return $rmf->mostRecentFiles($limit);
        });

        return $mostRecentFiles;
    }

    public static function assetsOutdated()
    {
        $publishedManifest = public_path('vendor/security/manifest.json');

        if (!File::exists($publishedManifest)) {
            return true;
        }

        $publishedManifest = json_decode(File::get($publishedManifest), true);

        $packageManifest = __DIR__ . '/../public/manifest.json';
        $packageManifest = json_decode(File::get($packageManifest), true);

        return $publishedManifest['version'] !== $packageManifest['version'];
    }

    public function cleanInput(string|array $input): string|array
    {
        return $this->antiXss->xss_clean($input);
    }
}

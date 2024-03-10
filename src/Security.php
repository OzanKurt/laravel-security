<?php

namespace OzanKurt\Security;

use Illuminate\Support\Facades\File;
use OzanKurt\Security\Helpers\RecentlyModifiedFiles;
use voku\helper\AntiXSS;
use voku\helper\UTF8;

class Security
{
    public AntiXSS $antiXss;

    public function __construct(AntiXSS $antiXss)
    {
        $this->antiXss = $antiXss;
    }

    public function route(string $route)
    {
        return route(config('security.dashboard.route_name').$route);
    }

    public function routeIsActive(string $route)
    {
        return request()->route()->getName() === config('security.dashboard.route_name') . $route;
    }

    public function getRecentlyModifiedFiles(int $time_range = 604800): array
    {
        $rmf = new RecentlyModifiedFiles(base_path(), 20000, 250000, $time_range);

        $rmf->run();

        $mostRecentFiles = $rmf->mostRecentFiles(15);

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

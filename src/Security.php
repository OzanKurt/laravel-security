<?php

namespace OzanKurt\Security;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use OzanKurt\Security\Enums\IpEntryType;
use OzanKurt\Security\Helpers\RecentlyModifiedFiles;
use voku\helper\AntiXSS;

class Security
{
    public AntiXSS $antiXss;
    public ?bool $ipWhitelistedInDatabase = null;

    public function __construct(AntiXSS $antiXss)
    {
        $this->antiXss = $antiXss;
    }

    public function isIpWhitelistedInDatabase()
    {
        if (! is_null($this->ipWhitelistedInDatabase)) {
            return $this->ipWhitelistedInDatabase;
        }

        $model = config('security.database.ip.model');

        // Check if the IP is whitelisted
        $ip = $model::query()
            ->where('entry_type', IpEntryType::WHITELIST)
            ->first();

        if ($ip) {
            $ip->increment('request_count');

            return $this->ipWhitelistedInDatabase = true;
        }

        return $this->ipWhitelistedInDatabase = false;
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
            $time_range = (int) $time_range->diffInSeconds(Carbon::now());
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

    public function highlightJson(string|array|null $json): string
    {
        if (is_null($json)) {
            $json = [];
        }

        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        ksort($json);

        $json = json_encode($json, JSON_PRETTY_PRINT);
        $json = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');

        $keywords = ['true', 'false', 'null'];

        // Replace JSON keywords with Monokai colors
        foreach ($keywords as $keyword) {
            $json = preg_replace('/\b' . $keyword . '\b/', "<span class='keyword'>$keyword</span>", $json);
        }

        // Highlight JSON keys with Monokai colors
        $json = preg_replace('/&quot;(.*?)&quot;:/', '"<span class="json-key">$1</span>":', $json);

        // Highlight JSON string values with Monokai colors
        $json = preg_replace('/&quot;(.*?)&quot;/', '"<span class="json-string">$1</span>"', $json);

        return "<pre class=\"mb-0\">{$json}</pre>";
    }

    public function logoHref()
    {
        return config('security.dashboard.logo_target_route_name')
            ? route(config('security.dashboard.logo_target_route_name'))
            : app('security')->route('dashboard.index');
    }
}

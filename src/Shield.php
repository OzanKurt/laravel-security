<?php

namespace OzanKurt\Shield;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use OzanKurt\Shield\Enums\IpEntryType;
use OzanKurt\Shield\Helpers\RecentlyModifiedFiles;
use voku\helper\AntiXSS;

class Shield
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

        $model = config('shield.database.ip.model');

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
        return route(config('shield.dashboard.route_name') . $route, $parameters, $absolute);
    }

    public function routeIsActive(string $route)
    {
        return request()->route()->getName() === config('shield.dashboard.route_name') . $route;
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
        $publishedManifest = public_path('vendor/shield/manifest.json');

        if (!File::exists($publishedManifest)) {
            return true;
        }

        $publishedManifest = json_decode(File::get($publishedManifest), true);

        $packageManifest = __DIR__ . '/../public/manifest.json';
        $packageManifest = json_decode(File::get($packageManifest), true);

        return $publishedManifest['version'] !== $packageManifest['version'];
    }

    public function correlationId(): string
    {
        return app(\OzanKurt\Shield\Support\CorrelationId::class)->get();
    }

    /**
     * Is a premium license active? Cached 24h, with a 7-day grace period
     * if the Central license-check API is unreachable. Soft enforcement —
     * see Services\Premium\LicenseChecker for the rationale.
     */
    public function isPremium(): bool
    {
        return app(\OzanKurt\Shield\Services\Premium\LicenseChecker::class)->isPremium();
    }

    /**
     * Is the named feature unlocked under the current premium license?
     * Returns false when no license key is configured, the license is
     * expired/revoked, or the feature is not in the plan's feature list.
     */
    public function isFeatureAvailable(string $feature): bool
    {
        return app(\OzanKurt\Shield\Services\Premium\LicenseChecker::class)->isFeatureAvailable($feature);
    }

    /**
     * Full license state for the License dashboard page. Includes plan,
     * expiry, domain limit + count, last-checked timestamp, and grace
     * status. License key itself is NOT exposed — use maskedKey() instead.
     *
     * MAY trigger a synchronous HTTP call to Central on cache miss. Use
     * licenseStateCached() in views/middleware/hot paths to avoid that.
     *
     * @return array<string,mixed>
     */
    public function licenseState(): array
    {
        return app(\OzanKurt\Shield\Services\Premium\LicenseChecker::class)->state();
    }

    /**
     * Cache-only license state for hot paths (navbar badge, every-request
     * checks, etc.). Returns null when no cache entry exists — callers
     * should render a neutral state, NEVER trigger a refresh from here.
     *
     * @return array<string,mixed>|null
     */
    public function licenseStateCached(): ?array
    {
        return app(\OzanKurt\Shield\Services\Premium\LicenseChecker::class)->cachedState();
    }

    /**
     * Scan an UploadedFile (or any path) via the configured scanner backends.
     *
     * @param \Symfony\Component\HttpFoundation\File\UploadedFile|string $fileOrPath
     * @return array{clean: bool, findings: array<int, array<string, mixed>>}
     */
    public function scanUploadedFile($fileOrPath): array
    {
        $path = is_object($fileOrPath) && method_exists($fileOrPath, 'getRealPath')
            ? $fileOrPath->getRealPath()
            : (string) $fileOrPath;

        return $this->scanFile($path);
    }

    /**
     * Scan an arbitrary file path with all available backends (native + ClamAV when present).
     *
     * @return array{clean: bool, findings: array<int, array<string, mixed>>}
     */
    public function scanFile(string $path): array
    {
        if (! is_file($path)) {
            return ['clean' => true, 'findings' => []];
        }

        $findings = [];

        foreach (app(\OzanKurt\Shield\Services\Scanner\Backends\NativeBackend::class)->scanFile($path) as $f) {
            $findings[] = $f + ['backend' => 'native'];
        }

        if (class_exists(\OzanKurt\Shield\Services\Scanner\Backends\ClamAvBackend::class)
            && class_exists(\Xenolope\Quahog\Client::class)) {
            try {
                $clamav = app(\OzanKurt\Shield\Services\Scanner\Backends\ClamAvBackend::class);
                if ($clamav->isAvailable()) {
                    foreach ($clamav->scanFile($path) as $f) {
                        $findings[] = $f + ['backend' => 'clamav'];
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'clean' => empty($findings),
            'findings' => $findings,
        ];
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
        return config('shield.dashboard.logo_target_route_name')
            ? route(config('shield.dashboard.logo_target_route_name'))
            : app('shield')->route('dashboard.index');
    }
}

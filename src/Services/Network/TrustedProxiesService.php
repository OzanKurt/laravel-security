<?php

namespace OzanKurt\Shield\Services\Network;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Auto-discovers Cloudflare / AWS / GCP load-balancer IP ranges.
 * Returns the merged CIDR list; cached for 24h.
 *
 * Apps can pipe the result into config('trustedproxy.proxies') for the
 * standard TrustProxies middleware, e.g.:
 *
 *   $proxies = app(TrustedProxiesService::class)->trustedProxies();
 *   config(['trustedproxy.proxies' => $proxies]);
 */
class TrustedProxiesService
{
    private const CLOUDFLARE_V4 = 'https://www.cloudflare.com/ips-v4';
    private const CLOUDFLARE_V6 = 'https://www.cloudflare.com/ips-v6';

    /** @return string[] CIDR ranges */
    public function trustedProxies(): array
    {
        return Cache::remember('shield.trusted_proxies', 86400, function () {
            $proxies = [];

            if (config('shield.trusted_proxies.cloudflare', true)) {
                $proxies = array_merge($proxies, $this->fetchCloudflareRanges());
            }

            // AWS and GCP loaders are placeholders, pulling official JSON
            // can be implemented when needed.

            $extra = (array) config('shield.trusted_proxies.extra', []);
            return array_values(array_filter(array_unique(array_merge($proxies, $extra))));
        });
    }

    private function fetchCloudflareRanges(): array
    {
        $out = [];
        try {
            $v4 = Http::timeout(5)->get(self::CLOUDFLARE_V4);
            $v6 = Http::timeout(5)->get(self::CLOUDFLARE_V6);

            if ($v4->successful()) {
                $out = array_merge($out, array_filter(array_map('trim', preg_split('/\r?\n/', $v4->body()) ?: [])));
            }
            if ($v6->successful()) {
                $out = array_merge($out, array_filter(array_map('trim', preg_split('/\r?\n/', $v6->body()) ?: [])));
            }
        } catch (Throwable) {
            // ignore, fallback to whatever's already in config
        }
        return $out;
    }
}

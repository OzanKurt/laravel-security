<?php

namespace OzanKurt\Shield\Services\Acl;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Acl\Matchers\AclMatcher;
use OzanKurt\Shield\Services\Acl\Matchers\AsnMatcher;
use OzanKurt\Shield\Services\Acl\Matchers\CidrMatcher;
use OzanKurt\Shield\Services\Acl\Matchers\CountryMatcher;
use OzanKurt\Shield\Services\Acl\Matchers\HostnameMatcher;
use OzanKurt\Shield\Services\Acl\Matchers\IpMatcher;
use OzanKurt\Shield\Services\Acl\Matchers\ReferrerRegexMatcher;
use OzanKurt\Shield\Services\Acl\Matchers\UserAgentRegexMatcher;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class AclEvaluator
{
    /** @var array<string, AclMatcher> */
    private array $matchers;

    public function __construct(
        private LookupResolver $lookups,
        IpMatcher $ip,
        CidrMatcher $cidr,
        CountryMatcher $country,
        AsnMatcher $asn,
        HostnameMatcher $hostname,
        UserAgentRegexMatcher $uaRegex,
        ReferrerRegexMatcher $refRegex,
    ) {
        $this->matchers = [
            'ip' => $ip,
            'cidr' => $cidr,
            'country' => $country,
            'region' => $country,        // region/city reuse country matcher in stub
            'city' => $country,
            'asn' => $asn,
            'hostname' => $hostname,
            'ua_regex' => $uaRegex,
            'ref_regex' => $refRegex,
        ];
    }

    public function evaluate(Request $request): Decision
    {
        $cacheKey = $this->decisionCacheKey($request);
        $ttl = config('shield.acl.decision_cache_ttl', 60);

        return Cache::remember($cacheKey, $ttl, function () use ($request) {
            return $this->evaluateUncached($request);
        });
    }

    public function evaluateUncached(Request $request): Decision
    {
        $entries = $this->liveEntries();

        // Tier 1: whitelist (allow)
        $whitelist = $entries->filter(fn ($e) => $this->isAction($e, 'allow'));
        foreach ($whitelist as $entry) {
            if ($this->matches($request, $entry)) {
                return Decision::allow($entry);
            }
        }

        // Tier 2: blacklist (permanent block)
        $blacklist = $entries->filter(fn ($e) => $this->isAction($e, 'blacklist'));
        foreach ($blacklist as $entry) {
            if ($this->matches($request, $entry)) {
                $entry->increment('hit_count');
                return Decision::blacklist($entry);
            }
        }

        // Tier 3: block (temporary)
        $block = $entries->filter(fn ($e) => $this->isAction($e, 'block'));
        foreach ($block as $entry) {
            if ($this->matches($request, $entry)) {
                $entry->increment('hit_count');
                return Decision::block($entry);
            }
        }

        return Decision::pass();
    }

    public function clearCache(): void
    {
        // Clear the live entries cache; per-request decision caches expire via TTL
        Cache::forget('shield.acl.live');
    }

    private function liveEntries()
    {
        return Cache::rememberForever('shield.acl.live', function () {
            return Acl::query()
                ->active()
                ->orderBy('id')
                ->get();
        });
    }

    private function matches(Request $request, Acl $entry): bool
    {
        $kindName = $this->lookups->name(AclKind::class, $entry->kind_id);
        if (! $kindName || ! isset($this->matchers[$kindName])) {
            return false;
        }
        return $this->matchers[$kindName]->matches($request, $entry->value);
    }

    private function isAction(Acl $entry, string $actionName): bool
    {
        return $entry->action_id === $this->lookups->id(AclAction::class, $actionName);
    }

    private function decisionCacheKey(Request $request): string
    {
        $ip = $request->header('CF-Connecting-IP') ?? $request->ip() ?? 'unknown';
        return 'shield.acl.decision.' . md5($ip . '|' . ($request->userAgent() ?? ''));
    }
}

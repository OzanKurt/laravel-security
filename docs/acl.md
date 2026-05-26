# ACL — Unified Access Control List

The `ls_acl` table holds every allow/deny entry — IPs, CIDR ranges, ASNs, countries, regexes — in one place. The `firewall.acl` middleware evaluates entries first-match-wins per tier and short-circuits the request when it finds a match.

## Evaluation order

For each request, the evaluator checks tiers in this order:

1. **Whitelist** (`action=allow`) — any match → ALLOW (request continues; later firewall middlewares skipped)
2. **Blacklist** (`action=blacklist`, permanent) — any match → DENY (403)
3. **Block** (`action=block`, usually with `expires_at`) — any match → DENY (403)
4. No match → PASS (request continues, subject to remaining firewall middlewares)

Within each tier: first match wins. Across kinds: IP-exact matches are checked first (cheapest), then CIDR, then user-agent / referrer regex, then country, region, city, ASN, hostname (only when configured).

## Kinds

| Kind | Stored value | Cost |
|---|---|---|
| `ip` | Exact IPv4/IPv6 | O(1) lookup |
| `cidr` | `10.0.0.0/24`, `2001:db8::/32` | O(N) via Symfony IpUtils |
| `asn` | `AS12345` (1.1+, requires `geoip2/geoip2`) | O(1) MaxMind DB lookup |
| `country` / `region` / `city` | ISO 3166 code or name | O(1) MaxMind DB lookup |
| `hostname` | reverse-DNS name | DNS call — opt-in, off by default |
| `ua_regex` | PHP regex matched against User-Agent | O(N) |
| `ref_regex` | PHP regex matched against Referer | O(N) |

## Auto-block

Each firewall middleware (xss / sqli / lfi / etc.) can auto-block its offender:

```php
'middleware' => [
    'xss' => [
        'auto_block' => [
            'attempts' => 3,
            'frequency' => 300,  // 3 attempts in 5 minutes
            'period' => 1800,    // block for 30 minutes
        ],
    ],
],
```

The `BlockAclEntryListener` creates an `ls_acl` row with `action=block`, `source=auto_block`, `expires_at=now()+period`. Honeypots and suspicion-scoring use the same flow with their own `source` tags.

## Caching

- Full live ACL: `Cache::rememberForever('shield.acl.live')` — invalidated on any `ls_acl` write via `AclObserver`
- Per-IP decision: `shield.acl.decision.<md5(ip|ua)>` with TTL from `shield.acl.decision_cache_ttl` (default 60s)
- GeoIP / ASN per-IP: cached per request lifetime

Clear cache from `/shield/cache` dashboard or via `php artisan cache:forget shield.acl.live`.

## Sweep expired entries

`shield:unblock-ips` (scheduled) deletes ACL entries past their `expires_at`.

## Programmatic add

```php
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\{AclKind, AclAction};
use OzanKurt\Shield\Services\Lookups\LookupResolver;

$lookups = app(LookupResolver::class);

Acl::create([
    'kind_id' => $lookups->id(AclKind::class, 'cidr'),
    'action_id' => $lookups->id(AclAction::class, 'blacklist'),
    'value' => '203.0.113.0/24',
    'source' => 'manual',
    'reason' => 'Persistent abuser ASN',
]);
```

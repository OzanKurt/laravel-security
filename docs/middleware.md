# Middleware reference

Every middleware Shield ships is a Laravel middleware alias registered by `ShieldServiceProvider::registerMiddleware()`. Attach them per-route, per-group, or globally via the `firewall.all` group.

## The `firewall.all` group

Apply Shield to a route group with one line:

```php
Route::middleware('firewall.all')->group(function () {
    // your routes
});
```

The group resolves to:
```
firewall.correlation → firewall.bypass → firewall.acl → firewall.live_traffic
  → (whatever's in config('shield.all_middleware'))
```

Customize the order in `config/shield.php`:

```php
'all_middleware' => [
    'firewall.ip',
    'firewall.agent',
    'firewall.bot',
    'firewall.lfi',
    'firewall.rfi',
    'firewall.php',
    'firewall.session',
    'firewall.sqli',
    'firewall.xss',
    'firewall.keyword',
    'firewall.headers',          // beta.5
    'firewall.score_threshold',  // beta.5
],
```

## Per-middleware reference

### Pipeline / glue

| Alias | Class | Purpose |
|---|---|---|
| `firewall.correlation` | `Http\Middleware\AttachCorrelationId` | Generates UUID7 per request; sets `X-Correlation-Id` response header. Accepts an existing incoming UUID via `X-Correlation-Id` request header. |
| `firewall.bypass` | `Firewall\Middleware\Bypass` | Checks `X-Security-Bypass` header against `LS_BYPASS_KEY` + checks client IP against `shield.bypass.ips`. On match, sets `shield.bypassed` attribute → later middlewares short-circuit. Audit-logs every bypass. |
| `firewall.acl` | `Firewall\Middleware\Acl` | Evaluates `ls_acl` first-match-wins (allow > blacklist > block > pass). 403 on deny. |
| `firewall.live_traffic` | `Http\Middleware\LiveTrafficCapture` | Terminable middleware — records the request after response is sent. Sampling per `shield.live_traffic.sample_rate`. |

### WAF detectors (port-from-config in beta.1)

Each loads patterns from `ls_waf_rules` filtered by category. On match: blocks (403) + logs + fires `AttackDetectedEvent` + maybe auto-blocks.

| Alias | Category | Detects |
|---|---|---|
| `firewall.ip` | n/a | DB-tracked block/blacklist entries (older `security_ips` model — being deprecated in favor of `firewall.acl`) |
| `firewall.agent` | `agent` | Browser/platform/device/property allow-block + malicious UA patterns |
| `firewall.bot` | `bot` | Crawler allow-block (Googlebot, Bingbot, scrapers) |
| `firewall.geo` | n/a | Continent/country/region/city filtering via configurable provider (`ipapi`, `ipstack`, etc.) |
| `firewall.lfi` | `lfi` | Local file inclusion (`../`, `etc/passwd`, etc.) |
| `firewall.rfi` | `rfi` | Remote file inclusion (`http://...` in request, with content verification) |
| `firewall.php` | `php_protocols` | PHP wrapper protocols (`php://`, `phar://`, `bzip2://`, etc.) |
| `firewall.session` | `session` | PHP serialized payload signatures |
| `firewall.sqli` | `sqli` | UNION SELECT / sqli keyword chains |
| `firewall.xss` | `xss` | `voku/anti-xss` + Blade-echo cleaner + custom XSS patterns |
| `firewall.swear` | `n/a` | Profanity / configurable wordlist |
| `firewall.url` | n/a | URL-pattern protected paths (logging only) |
| `firewall.referrer` | n/a | Static referrer blocklist |
| `firewall.keyword` | `keyword` | Path-keyword block (wp-admin, .env, .git, xmlrpc, etc.) |
| `firewall.whitelist` | n/a | Forces requests to come from whitelisted IPs only |

### Response shaping + extras (beta.5)

| Alias | Class | Purpose |
|---|---|---|
| `firewall.headers` | `Firewall\Middleware\SecurityHeaders` | Applies HSTS, CSP (with nonce), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy |
| `firewall.https` | `Firewall\Middleware\EnforceHttps` | 301 redirects HTTP → HTTPS in production |
| `firewall.disabled_routes` | `Firewall\Middleware\DisabledRoutes` | Unconditional 404 for configured patterns (e.g. `_ignition/*`, `install.php`) |
| `firewall.score_threshold` | (planned) | Blocks IPs whose suspicion score crossed the configured threshold |

### Uploads (beta.4)

| Alias | Class | Purpose |
|---|---|---|
| `firewall.av_uploads` | `Firewall\Middleware\AvUploads` | Streams `$request->allFiles()` through the scanner (Native + ClamAV). Rejects 415 + audit-logs on hit. Opt-in per route. |

## Per-middleware config

Every detector middleware accepts a uniform shape under `shield.middleware.<name>`:

```php
'middleware' => [
    'sqli' => [
        'enabled' => true,                // master toggle
        'methods' => ['post', 'put', 'patch', 'delete'],  // or ['all']
        'routes' => [
            'except' => ['health'],       // never check these paths
            'only' => [],                 // when non-empty, ONLY check these
        ],
        'inputs' => [
            'except' => ['password', 'note'],  // skip these input keys
            'only' => [],
        ],
        'auto_block' => [
            'attempts' => 3,
            'frequency' => 300,           // attempts within N seconds
            'period' => 1800,             // block duration in seconds
        ],
        'patterns' => [
            // legacy — beta.1 migrates this list into ls_waf_rules
            '#[\d\W]union select#i',
        ],
    ],
],
```

## Custom middleware

Build your own by extending `Firewall\AbstractMiddleware`. Set `$middleware` to the config key + provide `check(array $patterns): bool`:

```php
namespace App\Firewall;

use OzanKurt\Shield\Firewall\AbstractMiddleware;
use OzanKurt\Shield\Events\AttackDetectedEvent;

class CustomDetector extends AbstractMiddleware
{
    protected function check($patterns): bool
    {
        if ($this->request->input('custom_field') === 'malicious') {
            $log = $this->log();
            event(new AttackDetectedEvent($log));
            return true;
        }
        return false;
    }
}
```

Then alias + register in your `AppServiceProvider`:

```php
Route::aliasMiddleware('firewall.custom', \App\Firewall\CustomDetector::class);
```

And add to your `firewall.all` group via `config('shield.all_middleware')`.

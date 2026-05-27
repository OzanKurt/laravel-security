# Architecture

How Shield is wired internally + the extension points for custom behavior + premium hooks.

## Layers

```
┌─────────────────────────────────────────────────────────────┐
│                    Laravel HTTP kernel                      │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  firewall.all middleware group                              │
│    1. firewall.correlation  (UUID7 per request)             │
│    2. firewall.bypass       (header key + config IP check)  │
│    3. firewall.acl          (whitelist/blacklist/block)     │
│    4. firewall.live_traffic (terminable, sampled)           │
│    5. ...domain detectors (xss, sqli, lfi, etc.)            │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                  Application routes / handlers              │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼  (events fired throughout)
┌──────────────────┐  ┌──────────────────┐  ┌────────────────┐
│ AttackDetected   │  │ AuditLogger      │  │ ScannerRun     │
│   → Notifier     │  │   → HmacChain    │  │   → Backends   │
│   → ACL block    │  │   → ls_audit_log │  │   → findings   │
└──────────────────┘  └──────────────────┘  └────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                       ls_* tables                           │
│  logs · auth_logs · acl · audit_log · waf_rules ·           │
│  scanner_runs · findings · live_traffic · signatures · …    │
└─────────────────────────────────────────────────────────────┘
```

## Contracts (extension points)

Every pluggable piece is a contract under `OzanKurt\Shield\Contracts`:

| Contract | What pluggable | Default implementation |
|---|---|---|
| `StorageDriver` | DB write strategy | `Services\Storage\SyncStorageDriver` |
| `ScannerBackend` | File-scan engine | `Services\Scanner\Backends\NativeBackend` |
| `ThreatFeedProvider` | External feed source | `Services\ThreatFeed\Providers\SpamhausProvider` |
| `AuditSink` | Audit log destination | DB-only by default |
| `NotificationChannel` | Custom alert delivery | Laravel built-in channels |
| `SuspicionScorer` | Per-IP score tracking | `Services\Scoring\SuspicionScorer` (Redis sliding window) |
| `LicenseChecker` | Premium activation | `Services\Premium\LicenseChecker` |

### Adding a custom scanner backend

Implement the contract:

```php
namespace App\Shield;

use OzanKurt\Shield\Contracts\ScannerBackend;

class YaraBackend implements ScannerBackend
{
    public function name(): string
    {
        return 'yara';
    }

    public function isAvailable(): bool
    {
        return is_file('/usr/local/bin/yara');
    }

    public function isPerFile(): bool
    {
        return true;
    }

    public function scanFile(string $path): array
    {
        // invoke yara on $path via Symfony Process, parse results, return findings:
        //   [['signature_ref' => 'X', 'severity' => 'high', 'matched_content' => '...'], ...]
    }

    public function scanRun(): array
    {
        return [];
    }
}
```

Register the backend in your `AppServiceProvider::register()`:

```php
$this->app->extend(\OzanKurt\Shield\Services\Scanner\Scanner::class, function ($scanner, $app) {
    $scanner->addBackend(new \App\Shield\YaraBackend());
    return $scanner;
});
```

Then `php artisan shield:scan --backend=yara` uses it.

### Adding a custom threat feed provider

```php
namespace App\Shield;

use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;

class CustomFeed implements ThreatFeedProvider
{
    public function name(): string { return 'custom'; }
    public function label(): string { return 'My Custom Feed'; }
    public function isAvailable(): bool { return ! empty(config('services.customfeed.key')); }

    public function sync(): SyncResult
    {
        // pull, upsert into ls_acl or ls_waf_rules, return result
        return new SyncResult($this->name(), imported: 42);
    }
}
```

Register by appending to the providers array:

```php
config(['shield.threat_feed.providers' => array_merge(
    config('shield.threat_feed.providers'),
    [\App\Shield\CustomFeed::class],
)]);
```

`php artisan shield:feed-sync --source=custom` runs it.

### Adding an audit log sink

```php
namespace App\Shield;

use OzanKurt\Shield\Contracts\AuditSink;
use OzanKurt\Shield\Models\AuditLog;

class DatadogSink implements AuditSink
{
    public function push(AuditLog $record): void
    {
        Http::withHeaders(['DD-API-KEY' => config('services.datadog.api_key')])
            ->post('https://http-intake.logs.datadoghq.com/v1/input', [
                'ddsource' => 'laravel-shield',
                'service'  => config('app.name'),
                'event'    => $record->only(['kind_id', 'severity_id', 'description', 'ip', 'changes', 'meta']),
            ]);
    }
}
```

Tag the binding:

```php
$this->app->tag([DatadogSink::class], 'shield.audit_sinks');
```

The `AuditLogger` resolves all tagged sinks and pushes every record to each.

## Service container bindings

All in `ShieldServiceProvider::register()`:

| Binding | Resolves to |
|---|---|
| `Shield::class` + `'shield'` alias | The main `Shield` facade target |
| `LookupResolver::class` | Singleton — cached name→id lookups |
| `AclEvaluator::class` | Singleton |
| `WafRuleResolver::class` | Singleton |
| `HmacChain::class` | Singleton — wraps `LS_AUDIT_HMAC_SECRET` |
| `AuditLogger::class` | Singleton |
| `Scanner::class` | Singleton, backends array assembled from contract bindings |
| `SuspicionScorer::class` | Singleton |
| `FeedRunner::class` | Singleton, providers from `shield.threat_feed.providers` |
| `LicenseChecker::class` | Singleton (premium); resolves to free-stub when premium not active |
| `CorrelationId::class` | Singleton, generates UUID7 on first access per request |
| `CspNonce::class` | Singleton, generates per-request nonce |
| `RequestDataRedactor::class` | Singleton, redacts sensitive fields before persistence |

## Premium binding pattern

Premium features live in the **same package** under `src/Premium/`. Activation is runtime-gated via `Shield::isFeatureAvailable($feature)`. Pattern:

```php
$this->app->bind(\OzanKurt\Shield\Contracts\ThreatFeedProvider::class, function ($app) {
    $shield = $app->make(\OzanKurt\Shield\Shield::class);
    return $shield->isFeatureAvailable('realtime_feed')
        ? new \OzanKurt\Shield\Premium\RealtimeThreatFeedProvider()
        : new \OzanKurt\Shield\Services\ThreatFeed\Providers\SpamhausProvider();
});
```

Resolution happens at call time (not at boot), so a license expiry mid-runtime causes the next request to fall back to the free implementation. See [premium.md](premium.md) for the soft-enforcement model + threat model.

## Events dispatched

| Event | When | Used by |
|---|---|---|
| `AttackDetectedEvent` | WAF middleware blocks a request | `AttackDetectedListener` → notifications + `BlockAclEntryListener` → auto-block |
| `Auth::Login` (Laravel) | User signs in | `SuccessfulLoginListener` → audit + auth log |
| `Auth::Failed` (Laravel) | Failed login | `FailedLoginListener` → audit + auth log + auto-block |
| `FileChangeDetectedEvent` | `shield:watch` detects a file change | Audit log + optional focused scan |
| `LiveTrafficCapturedEvent` | Live traffic capture (when real-time broadcasting on) | `Reverb`/`Pusher`/`Ably` → dashboard live update |

Listen to any of these for custom workflows:

```php
Event::listen(\OzanKurt\Shield\Events\AttackDetectedEvent::class, function ($event) {
    // $event->log is the ls_logs row
    // Custom alerting, scoring, integrations, etc.
});
```

## Data model summary

| Table | Schema purpose |
|---|---|
| `ls_logs` | Attack/firewall-hit records — IP, middleware, URL, request_data |
| `ls_auth_logs` | Login attempts |
| `ls_acl` | Unified allow/deny list — kind_id, value, action_id, expires_at |
| `ls_audit_log` | HMAC-chained admin/state-change trail |
| `ls_waf_rules` | DB-backed firewall rules (replaces `config/security.php` regex arrays) |
| `ls_signatures` | Malware signatures from the GitHub-Releases feed |
| `ls_scanner_runs` / `ls_scanner_findings` | Scan history + per-file detections |
| `ls_live_traffic` | Sampled request stream |
| `ls_*_kinds`, `ls_*_actions`, `ls_*_statuses` | Lookup tables (no PHP enums anywhere) |

Every model implements `HasUuid` + `HasUserstamps` + `SoftDeletes` and is bound to the configured DB connection at construction time.

## Why no PHP enums

The FK-to-lookup-table pattern beats `EnumCasts::class` because:
- Lookup values can be added at runtime by feed providers (e.g. ClamAV signature categories) without code changes
- Cross-DB compatibility (MySQL ENUM differs from Postgres ENUM)
- Visibility — every value exists as a queryable row, surfaceable in the dashboard

`LookupResolver` caches the name↔id mapping forever (invalidated only by manual `cache:clear`), so the FK joins cost nothing in the hot path.

## Why `ls_` prefix

The package was renamed from `ozankurt/laravel-security` to `ozankurt/laravel-shield` in v1.0. The DB prefix changed from `security_` to `ls_` (Laravel Shield initials). Both prefixes are configurable via `FIREWALL_DB_PREFIX` for legacy installs that want to keep their old data.

## Octane / Reverb compatibility

Singletons that hold per-request state (`CorrelationId`, `CspNonce`, `Shield::$ipWhitelistedInDatabase`) need to be reset between requests under Octane. Shield's `ShieldServiceProvider::boot()` registers a Laravel `RequestHandled` listener (or Octane equivalent) to reset these — verify yours is firing before deploying under Octane in production.

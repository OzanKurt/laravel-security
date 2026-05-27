# Troubleshooting

Common issues with concrete fixes. If your symptom isn't here, open an issue: <https://github.com/OzanKurt/laravel-shield/issues>.

## Locked out of your own app

### Symptom
You added an ACL block (or scoring auto-blocked you) and now every request returns 403.

### Fix
You have three independent bypass mechanisms — see [bypass.md](bypass.md). Quickest:

```bash
# From CLI on the server:
php artisan shield:bypass-add YOUR.PUBLIC.IP

# Or set in .env then restart workers:
LS_BYPASS_IPS=YOUR.PUBLIC.IP

# Or pass the bypass key header from any request:
curl -H "X-Security-Bypass: $LS_BYPASS_KEY" https://your-app.com/shield
```

## Auto-block isn't firing

### Symptom
Attacks are logged in `/shield/logs` but no `ls_acl` block row appears.

### Causes + fixes

1. **`auto_block` not configured for that middleware.** Check `config/shield.php` under `shield.middleware.<name>.auto_block` — needs `attempts`, `frequency`, `period` keys.
2. **Window not exceeded yet.** Auto-block requires `attempts` attacks within `frequency` seconds. Lower the threshold if needed.
3. **Logs aren't writing to the new `ls_logs` table.** Check `SELECT COUNT(*) FROM ls_logs WHERE created_at > NOW() - INTERVAL 1 HOUR` — if 0, you're hitting the legacy `BlockIpListener` that targets the dropped `security_ips` table. Workaround until the listener is ported: add manual ACL rows or use the suspicion scorer instead.

## Dashboard returns 403

### Symptom
`/shield` returns 403 even when authenticated.

### Fix
The gate isn't defined. In your `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewShieldDashboard', fn ($user) => $user?->is_admin === true);
}
```

If your User model doesn't have `is_admin`, adjust the closure.

## Tests crash with "Class OzanKurt\Shield\Models\Ip not found" or "table security_ips does not exist"

### Symptom
After upgrading from 0.x, your test suite or production hits exceptions referencing the old `Ip` model or `security_ips` table.

### Fix
Beta.1 dropped the legacy tables. Five places still reference the old code:

- `src/Listeners/BlockIpListener.php`
- `src/Commands/UnblockIpsCommand.php`
- `src/Shield.php::isIpWhitelistedInDatabase()`
- `src/Firewall/Middleware/Ip.php`
- `src/Notifications/AttackDetectedNotification.php::via()`

These are scheduled for porting in a follow-up patch. Workaround: comment out the listener registration in `ShieldServiceProvider::registerListeners()` if it's crashing your boot.

## Scanner finishes with 0 findings even though I know there's malware

### Symptom
`php artisan shield:scan` completes successfully with `findings_count = 0` but you can manually verify there's a webshell on disk.

### Causes + fixes

1. **You requested a backend that isn't registered.** The Scanner singleton only has `NativeBackend` registered by default. `--backend=clamav` or `--backend=composer_audit` will silently no-op until you register them:

   ```php
   // AppServiceProvider::register()
   $this->app->extend(\OzanKurt\Shield\Services\Scanner\Scanner::class, function ($scanner) {
       $scanner->addBackend(app(\OzanKurt\Shield\Services\Scanner\Backends\ClamAvBackend::class));
       $scanner->addBackend(app(\OzanKurt\Shield\Services\Scanner\Backends\ComposerAuditBackend::class));
       return $scanner;
   });
   ```

2. **Signatures aren't seeded.** Check `SELECT COUNT(*) FROM ls_signatures WHERE is_enabled = 1`. If 0, run `php artisan shield:signatures-sync --embedded` to load the bundled baseline.

3. **The file exceeds `max_file_bytes`.** Native backend skips files over `shield.scanner.native.max_file_bytes` (default 5 MB). Raise the limit or scan such files manually.

## ClamAV daemon not reachable

### Symptom
`php artisan shield:clamav-status` reports unreachable, but you've installed clamav-daemon.

### Causes + fixes

1. **Socket path mismatch.** Default is `/var/run/clamav/clamd.ctl` (Ubuntu) but Debian uses `/var/run/clamav/clamd.sock`. Set `LS_CLAMAV_SOCKET` to match.
2. **Permissions.** The PHP-FPM user (often `www-data`) needs read+write on the socket. `chmod 660 /var/run/clamav/clamd.ctl && chgrp www-data /var/run/clamav/clamd.ctl`.
3. **Daemon not running.** `sudo systemctl status clamav-daemon`. If it's been killed by the OOM killer, increase clamd's memory or schedule scans during off-peak.

## Signature sync fails with HTTP 403

### Symptom
`shield:signatures-sync` reports `Remote returned HTTP 403`.

### Cause
GitHub API rate limit (60 req/h for unauthenticated). Sync falls back to embedded signatures automatically.

### Fix
Optionally authenticate the GitHub API call by setting `GITHUB_TOKEN` in `.env` — `LS_SIGNATURE_URL` requests will include the token.

## Audit log chain breaks ("chain mismatch at id N")

### Symptom
`php artisan shield:audit-verify` reports a broken chain.

### Causes + fixes

1. **Someone manually edited a row.** Quote the audit log row, restore the original data, and re-verify. The chain is tamper-evident — a verification failure is the alarm working.
2. **Race condition on parallel writes.** Concurrent `AuditLogger::log()` calls can race on reading the previous row. Switch storage driver to `queue` so writes serialize:

   ```env
   LS_STORAGE_DRIVER=queue
   ```

3. **`LS_AUDIT_HMAC_SECRET` was rotated without `shield:audit-rotate-secret`.** Rotate properly: keep the old secret, run `php artisan shield:audit-rotate-secret`, then update `.env`.

## Live traffic page shows no activity

### Symptom
You're hitting the app but `/shield/live-traffic` is empty.

### Causes + fixes

1. **Sampling discarded the requests.** Default sample rate is 0.1 (1 in 10). Hit the page 20+ times to see entries, or set `LS_LIVE_TRAFFIC_SAMPLE_RATE=1.0` temporarily.
2. **`firewall.live_traffic` isn't in your middleware stack.** It's auto-included in `firewall.all` but if you wired middlewares à la carte, add `firewall.live_traffic` to your route group.
3. **Skip pattern is too broad.** Check `shield.live_traffic.skip_paths` — default skips `shield/*`, `_debugbar/*`, asset paths, health checks.

## Premium features not activating

### Symptom
You set `LS_PREMIUM_LICENSE_KEY` but `Shield::isPremium()` returns false.

### Causes + fixes

1. **Cache hasn't expired yet.** License check is cached 24h. Run `php artisan cache:forget shield.premium.license` to force re-check.
2. **License-check API unreachable.** During the 7-day grace period after unreachability, the cached "valid" status persists. After the grace expires, features deactivate. Check `php artisan shield:license` for grace state.
3. **Domain limit exceeded.** Each license activates on a fixed number of domains. The dashboard's "License" page shows `domains_used / domain_limit` — contact support to extend if needed.

## CSP nonce isn't working

### Symptom
You enabled `shield.headers.csp.use_nonce` but inline scripts are still blocked by browser CSP.

### Causes + fixes

1. **`@cspNonce` directive not in your Blade output.** Use `<script nonce="@cspNonce">…</script>` for every inline script.
2. **CSP policy missing `'nonce-PLACEHOLDER'` token.** The middleware substitutes the placeholder; without it, a second script-src is appended (and browsers honor only the first). Update your policy to:

   ```php
   'policy' => "default-src 'self'; script-src 'self' 'nonce-PLACEHOLDER'; style-src 'self' 'unsafe-inline'",
   ```

## Apache vhost not picked up after edits (Laragon-specific)

### Symptom
You added a new `<host>.test.conf` in `sites-enabled/`, reloaded Apache via Laragon's tray menu, but the new host returns the default "Laragon" page.

### Fix
Laragon's tray "Reload" doesn't always restart Apache. Hard-kill + restart:

```powershell
Get-Process httpd | Stop-Process -Force
Start-Sleep 2
Start-Process 'C:\laragon\bin\apache\httpd-X.Y.Z\bin\httpd.exe' `
    -ArgumentList '-d','C:/laragon/bin/apache/httpd-X.Y.Z' -WindowStyle Hidden
```

(See `epic-skills/epic-laragon` for the full elevated-restart pattern.)

## Reporting a bug

If none of the above fixes your issue, include the output of these commands in your bug report:

```bash
php artisan shield:diag              # full system snapshot
php -v && composer --version          # tooling versions
git rev-parse HEAD                    # Shield package commit
php artisan about | grep -i shield    # registered providers/middlewares
```

Open the issue at <https://github.com/OzanKurt/laravel-shield/issues>.

# Dashboard

Bootstrap 5 + DataTables.net direct (no Yajra). Mobile-responsive. Mounted at `/shield/*` by default.

## Granting access

The dashboard is locked behind a gate. Define it in your `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewShieldDashboard', fn ($user) => $user?->is_admin === true);
}
```

The `ShieldDashboardMiddleware` calls `Gate::allows('viewShieldDashboard')` on every dashboard request. 403 otherwise.

## Page tour

| URL | Page | What |
|---|---|---|
| `/shield` | Dashboard | Stats cards (attacks, ACL active, recent events), recently modified files widget |
| `/shield/acl` | ACL | Unified allow/deny entries with kind/value/action/source filters, per-row whitelist/blacklist/delete |
| `/shield/logs` | Attack logs | Every `AttackDetectedEvent` row from the firewall middlewares — searchable, paginated |
| `/shield/auth-logs` | Auth logs | Login attempts (success + failure) emitted by Laravel `Auth::Login` / `Auth::Failed` events |
| `/shield/audit-log` | Audit log | HMAC-chained admin/state-change trail, filterable by kind + severity + actor + correlation_id |
| `/shield/live-traffic` | Live traffic | Sampled traffic stream with 5–10s polling (or real-time when broadcasting enabled) |
| `/shield/scanner` | Scanner | Overview cards + manual scan trigger + tabs for runs, findings, signatures |
| `/shield/rules` | WAF rules | Built-in + user-defined rules CRUD with toggle, edit (user-only), delete (user-only) |
| `/shield/threat-feed` | Threat feeds | Per-provider status + last sync result (1.1+) |
| `/shield/composer-audit` | Composer audit | Known CVEs in installed Composer packages (1.2+) |
| `/shield/cache` | Cache | Inspect every `shield.*` cache key + per-key clear + "Clear all" button (Debugbar-style) |
| `/shield/diagnostics` | Diagnostics | Sysinfo + OWASP environment audit grade (1.2+) |
| `/shield/settings` | Settings | Read-only summary of resolved config + last-install timestamps |

## AJAX action protocol

Server responses for per-row actions follow a uniform shape:

```json
{
  "actions": [
    {
      "type": "toastr",
      "data": {
        "type": "success",
        "title": "IP blacklisted",
        "message": "1.2.3.4 added to blacklist"
      }
    },
    {
      "type": "reloadDataTable",
      "data": { "dataTableId": "aclDataTable" }
    }
  ]
}
```

Supported `type` values:

| Type | What it does |
|---|---|
| `toastr` | Pop a toastr notification (top-right by default) |
| `reloadDataTable` | Reload the named datatable's ajax data |
| `confirmDialog` | Open a confirm dialog before the action commits (planned) |
| `redirect` | Redirect to a new URL (planned) |

Custom controllers in your app can return the same shape — the dashboard's `ajaxComplete` handler will process the actions automatically when called via the standard ajax pattern.

## Mobile responsiveness

Every table uses DataTables.net's `responsive: true` mode. Columns collapse into expandable detail rows on narrow viewports. Test target: 360px viewport (smallest common mobile).

## Cache management

`/shield/cache` shows every cache key Shield uses, whether each is currently populated, and a per-key clear button. Use it when:

- You manually edited the `ls_acl` table and want the cached live set to refresh
- An auto-block isn't firing and you suspect the per-IP decision cache is stale
- After a deploy, signature/rule changes aren't visible in the dashboard

The "Clear all" button purges every `shield.*` key in one go — useful after a major schema migration.

## Theme switcher

The navbar includes a light/dark theme toggle (powered by Bootstrap 5's `data-bs-theme` attribute). Preference persists in localStorage.

## Customizing views

Publish the views:

```bash
php artisan vendor:publish --tag=shield-views
```

Edit `resources/views/vendor/shield/dashboard/*` to override any page. Layout lives at `resources/views/vendor/shield/layouts/bootstrap/app.blade.php`.

## Filament alternative

If your app already runs Filament, install the official adapter instead:

```bash
composer require ozankurt/laravel-shield-filament
```

Register the plugin in your PanelProvider:

```php
return $panel->plugin(\OzanKurt\ShieldFilament\ShieldPlugin::make());
```

See the [adapter repo](https://github.com/OzanKurt/laravel-shield-filament) for the v1.x (Filament 3+4) vs v2.x (Filament 5+) split.

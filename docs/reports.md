# Reports

Five cadences with one shared report shape. Configurable per-channel routing. Wordfence-style executive email layout.

## Cadences

| Cadence | Default | Cron | Window |
|---|---|---|---|
| `daily_digest` | off | `0 8 * * *` | last 24h |
| `3_day` | off | `0 8 */3 * *` | last 72h |
| `7_day` | **on** | `0 8 * * 1` (Mondays) | last 7d |
| `14_day` | off | `0 8 1,15 * *` (1st + 15th) | last 14d |
| `30_day` | off | `0 8 1 * *` (1st of month) | last 30d |

Enable + configure each in `config/shield.php`:

```php
'reports' => [
    'daily_digest' => [
        'enabled' => env('LS_REPORT_DAILY', false),
        'cron_expression' => '0 8 * * *',
        'channels' => ['mail'],
        'include_severities' => ['low', 'medium'],
        'group_by' => 'kind',
        'top_n' => 10,
    ],
    // ... others
],
```

## CLI

```bash
# Render the latest payload to stdout (for inspection / testing templates)
php artisan shield:report-test --cadence=7_day

# Force-send a cadence now (bypasses the schedule)
php artisan shield:report-send 7_day
```

## What's in the report

Each cadence builds a uniform payload via `CadenceReportGenerator::build($cadence)`:

```php
[
    'cadence' => '7_day',
    'window_days' => 7,
    'start' => '2026-05-20T00:00:00+00:00',
    'end' => '2026-05-27T00:00:00+00:00',
    'site_url' => 'https://your-app.com',
    'sections' => [
        'top_blocked_ips' => [...],         // ip, country, hit_count, reason
        'top_blocked_countries' => [...],    // country, count
        'top_failed_logins' => [...],        // email, attempts, existing_user
        'recent_blocked_attacks' => [...],   // time, ip, action, url
        'recently_modified_files' => [...],  // path, mtime
        'required_updates' => [...],         // composer audit findings (1.2+)
    ],
]
```

Sections can be toggled on/off per-cadence via `shield.reports.<cadence>.sections` (planned, currently all sections render if data exists).

## Wordfence-style executive email

The default mail template renders the payload as a single-column responsive email:

```
┌──────────────────────────────────────────┐
│ ⚡ Laravel Shield                        │
│ Weekly report · May 20 – May 27          │
├──────────────────────────────────────────┤
│ Top blocked IPs                          │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━     │
│ 203.0.113.5    🇷🇺 Russia    142 hits   │
│ 198.51.100.42  🇨🇳 China     98 hits    │
│ ...                                       │
├──────────────────────────────────────────┤
│ Top failed logins                        │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━     │
│ admin@yourcompany.com   34 attempts ⚠   │
│ root                    12 attempts ✗   │
│ ...                                       │
├──────────────────────────────────────────┤
│ Recent blocked attacks                   │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━     │
│ 14:32 · 203.0.113.5 · SQLi · /api/users  │
│ 14:31 · 203.0.113.5 · XSS  · /search     │
│ ...                                       │
├──────────────────────────────────────────┤
│ Recently modified files                  │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━     │
│ app/Models/User.php     2 days ago       │
│ config/app.php          4 days ago       │
│ ...                                       │
├──────────────────────────────────────────┤
│ Required updates                         │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━     │
│ laravel/framework  10.48.2 → 10.49.0     │
│   CVE-2024-XXXXX  high severity          │
│ ...                                       │
├──────────────────────────────────────────┤
│ [ View full dashboard → ]                │
└──────────────────────────────────────────┘
```

Publish the template to customize:

```bash
php artisan vendor:publish --tag=shield-views
# Edit resources/views/vendor/shield/notifications/cadence-report.blade.php
```

## Routing per cadence

The `channels` array in each cadence config specifies where the report goes:

```php
'7_day' => [
    'enabled' => true,
    'cron_expression' => '0 8 * * 1',
    'channels' => ['mail', 'slack', 'webhook'],  // any combination
    'top_n' => 20,
],
```

For per-channel config (recipient email, Slack webhook URL, etc.), see [notifications.md](notifications.md#channels).

## Severity routing for individual events (not reports)

Reports are scheduled summaries. **Individual attack events** route through the matrix at `shield.notifications.routing`:

```php
'attack_detected' => [
    'critical' => ['mail', 'discord', 'webhook'],
    'high'     => ['mail', 'slack'],
    'medium'   => ['slack'],
    'low'      => [],     // digest only, rolled into the daily_digest cadence
],
```

`'low'` and `'medium'` events that route to `[]` get accumulated into the next cadence digest instead of dispatching immediately.

## Throttling within a cadence

Coalesce repeated event types within a window:

```php
'throttle' => [
    'attack_detected' => [
        'window' => 300,         // 5 minutes
        'group_by' => ['ip', 'middleware'],
        'max_per_window' => 1,
        'continuation_message' => '… and N additional attacks from the same IP in the last 5 min',
    ],
],
```

The throttle state lives in cache. Clear from `/shield/cache` if a stuck throttle is suppressing legitimate alerts.

## Webhook payload contract

The webhook channel emits a versioned JSON payload designed for consumption by the future Shield Central aggregator + any third-party SIEM:

```json
{
  "_meta": {
    "schema_version": "1.0",
    "site_id": "your-app.com"
  },
  "event": "report.weekly",
  "severity": "info",
  "correlation_id": "0192ec5b-...",
  "ts": "2026-05-27T08:00:00Z",
  "summary": "Weekly report: 247 attacks blocked, 12 IPs, 1 failed-login spike",
  "links": {
    "dashboard": "https://your-app.com/shield"
  },
  "data": { /* full payload, same shape as shield:report-test output */ }
}
```

`schema_version` bumps whenever the payload shape changes incompatibly. Consumers should ignore unknown fields.

## Telegram channel quirks

Telegram messages have a 4096-character body limit. Long reports get truncated with a "[…N more]" suffix. For full reports, use mail or webhook channels, Telegram is best for high-severity individual events.

## Testing your report template

```bash
# Render to stdout
php artisan shield:report-test --cadence=7_day > preview.json

# Trigger a real send to a single channel
php artisan shield:report-send 7_day --channel=mail
```

Combine with Mailtrap or `MAIL_MAILER=log` to preview the rendered HTML without spamming real recipients.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Scheduled report doesn't fire | Laravel scheduler not running | Add `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` to crontab |
| Report email is empty | Window had 0 events | Expected, no email is sent when all sections are empty unless `shield.reports.<cadence>.send_when_empty` is true |
| Recipients receive duplicate reports | Multiple cron entries OR Horizon retrying a failed dispatch | Check `php artisan schedule:list` for duplicates; check `failed_jobs` table for retried sends |
| Webhook payload missing `data` section | Payload schema_version mismatch with consumer | Consumer needs to handle `schema_version >= 1.0` flexibly; do not require unknown fields |

# Notifications

Five channels. Severity-routed. Throttled. Multi-cadence reports.

## Channels

| Channel | Status |
|---|---|
| `mail` | Built-in |
| `slack` | Built-in |
| `discord` | Built-in (custom channel) |
| `telegram` | Built-in (1.0+) — Bot API, MarkdownV2 |
| `webhook` | Built-in (1.0+) — generic POST + stable payload for the future Central app |

## Routing matrix

Configure per event kind × severity → channels:

```php
'notifications' => [
    'routing' => [
        'attack_detected' => [
            'critical' => ['mail', 'discord', 'webhook'],
            'high' => ['mail', 'slack', 'discord'],
            'medium' => ['slack', 'discord'],
            'low' => [],  // digest only
        ],
        'auth_failed' => [
            'critical' => ['mail', 'telegram'],
            'high' => ['slack'],
        ],
        'scanner_finding' => [
            'critical' => ['mail', 'discord'],
            'high' => ['mail'],
        ],
        // ...
    ],
],
```

## Throttling

Coalesce repeats:

```php
'throttle' => [
    'attack_detected' => [
        'window' => 300,           // 5 minutes
        'group_by' => ['ip', 'middleware'],
        'max_per_window' => 1,
        'continuation_message' => 'N additional similar attacks suppressed in last 5min',
    ],
],
```

Throttle state lives in Laravel cache (Redis recommended). Clear from `/shield/cache`.

## Multi-cadence reports

The old single weekly report is now one of many:

```php
'reports' => [
    'daily_digest' => [
        'enabled' => true,
        'cron_expression' => '0 8 * * *',
        'channels' => ['mail'],
        'include_severities' => ['low', 'medium'],
        'group_by' => 'kind',
    ],
    '3_day' => ['enabled' => false, 'cron_expression' => '0 8 */3 * *', 'channels' => ['mail']],
    '7_day' => ['enabled' => true, 'cron_expression' => '0 8 * * 1', 'channels' => ['mail']],
    '14_day' => ['enabled' => false, 'cron_expression' => '0 8 1,15 * *', 'channels' => ['mail']],
    '30_day' => ['enabled' => false, 'cron_expression' => '0 8 1 * *', 'channels' => ['mail']],
],
```

Each cadence renders an executive-summary email modelled on Wordfence's format:
- Top N blocked IPs (with country flags)
- Top N blocked countries (distinct IPs + total blocks)
- Top N failed logins (username + whether user exists)
- Recent blocked attacks
- Recently modified files
- Required updates (composer packages with CVEs from `shield:composer-audit`)

CTAs link back to the dashboard pages.

## Webhook channel payload (stable contract)

The webhook channel emits a versioned payload designed for consumption by the Shield Central app and any third-party integration:

```json
{
  "_meta": { "schema_version": "1.0", "site_id": "<env: LS_NOTIFY_WEBHOOK_SITE_ID>" },
  "event": "attack_detected",
  "severity": "high",
  "correlation_id": "0193e8b9-...",
  "ts": "2026-05-26T14:32:11Z",
  "summary": "Auto-blocked IP 1.2.3.4 after 3 SQLi attempts",
  "links": {
    "dashboard": "https://your-app.example.com/shield/logs?correlation_id=..."
  },
  "data": { /* event-specific payload */ }
}
```

## Telegram setup

```env
LS_NOTIFY_TELEGRAM_ENABLED=true
LS_NOTIFY_TELEGRAM_BOT_TOKEN=123456:ABC-...
LS_NOTIFY_TELEGRAM_CHAT_ID=-1001234567890
```

Create a bot via [@BotFather](https://t.me/BotFather), grab its token, add it to your group, and find the chat id with [getUpdates](https://core.telegram.org/bots/api#getupdates).

## CLI

```bash
# Trigger a specific cadence
php artisan shield:report-send 7-day

# Render the report to stdout for inspection
php artisan shield:report-test --cadence=daily-digest
```

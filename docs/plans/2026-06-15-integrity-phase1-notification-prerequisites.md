# Integrity Phase 1 - Notification Prerequisites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the three shared notification-layer defects that the upcoming file-integrity summary card depends on, so that mail/Slack/Discord delivery actually resolves and renders.

**Architecture:** This is the first of five sequenced plans implementing spec-005 ([docs/specs/spec-005-file-integrity-scan-notification.md](../specs/spec-005-file-integrity-scan-notification.md), Section 9). It touches only the shared notification plumbing: the missing Slack channel package, `Notifiable`'s wrong config paths, and the `DiscordMessage` serializer bugs. It deliberately does NOT repair the (separately broken) login/attack notifications, see "Out of scope" below.

**Tech Stack:** PHP 8, Laravel 10 (package supports 9-12), Orchestra Testbench + PHPUnit, `laravel/slack-notification-channel`.

---

## Context the implementer needs

- This is a Composer **package** (`ozankurt/laravel-shield`), not an app. Tests run via Orchestra Testbench. The base test class is `OzanKurt\Shield\Tests\TestCase` ([tests/TestCase.php](../../tests/TestCase.php)); unit tests instantiate classes directly and set values with `config([...])`.
- Run the full suite with `vendor/bin/phpunit`. Run one test with `vendor/bin/phpunit --filter test_name`.
- The notification config has two layers ([config/shield.php:71-143](../../config/shield.php#L71)):
  - `shield.notifications.<event>` = `{ enabled, channels: [...] }` (per-event toggles; `channels` is a flat list of channel names).
  - `shield.notification_channels.<channel>` = delivery settings. For `mail` the recipient is `notification_channels.mail.to`; for `slack` the **incoming webhook URL** is `notification_channels.slack.to`; for `discord` the webhook is `notification_channels.discord.webhook_url`.
- `OzanKurt\Shield\Notifications\Notifiable` ([src/Notifications/Notifiable.php](../../src/Notifications/Notifiable.php)) is the singleton routing target. It currently reads `shield.notifications.*` (the wrong layer), so mail/slack recipients resolve to `null`.
- `OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage` ([src/Notifications/Channels/Discord/DiscordMessage.php](../../src/Notifications/Channels/Discord/DiscordMessage.php)) builds the Discord webhook payload. Its `toArray()` has three bugs (see Task 3).
- Laravel 10 removed the legacy Slack notification channel from the framework. `Illuminate\Notifications\Messages\SlackMessage` does not exist until `laravel/slack-notification-channel` is installed. That package ships BOTH the legacy webhook `Messages\SlackMessage` (used here, routes via an incoming webhook URL) and the newer Block Kit API; a webhook-URL return from `routeNotificationForSlack()` selects the legacy webhook path, matching how Discord already works.

## Out of scope (flag, do not fix here)

`AttackDetectedNotification`, `FailedLoginNotification`, `SuccessfulLoginNotification`, and `SecurityReportNotification` are independently broken (wrong event key in `FailedLoginNotification::__construct`, `via()` iterating a flat list as if keyed, the `$this->$this->config` fatal in `viaQueues()`, and `$this->log`/`$this->notifications` references that do not exist). Repairing them is a separate bug-fix tracked outside this plan. This plan only fixes the SHARED pieces (Slack dependency, `Notifiable`, `DiscordMessage`) that the new integrity notification will reuse; the integrity notification (a later plan) implements its own correct `via()`.

---

## Task 1: Add the Slack notification channel package

**Files:**
- Modify: `composer.json` (require section)
- Test: `tests/Unit/Notifications/SlackChannelAvailableTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications;

use OzanKurt\Shield\Tests\TestCase;

class SlackChannelAvailableTest extends TestCase
{
    public function test_legacy_slack_message_class_is_available(): void
    {
        $this->assertTrue(
            class_exists(\Illuminate\Notifications\Messages\SlackMessage::class),
            'laravel/slack-notification-channel must be installed so the legacy webhook SlackMessage exists.'
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_legacy_slack_message_class_is_available`
Expected: FAIL (assertion false; the class does not exist yet on Laravel 10).

- [ ] **Step 3: Install the package**

Run: `composer require laravel/slack-notification-channel`

Let Composer resolve the version compatible with the installed Laravel. After install, verify the legacy class file is present:

Run: `composer show laravel/slack-notification-channel`
Expected: a version is listed, and `vendor/laravel/slack-notification-channel/src/Messages/SlackMessage.php` exists.

If the resolved version does NOT ship `Illuminate\Notifications\Messages\SlackMessage` (legacy webhook), stop and report: the Slack rendering approach in spec-005 (legacy incoming webhook) assumes it. Do not silently switch to Block Kit, which requires a bot token.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter test_legacy_slack_message_class_is_available`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock tests/Unit/Notifications/SlackChannelAvailableTest.php
git commit -m "feat(notifications): add laravel/slack-notification-channel for webhook Slack"
```

---

## Task 2: Fix `Notifiable` to read the `notification_channels` config layer

**Files:**
- Modify: `src/Notifications/Notifiable.php:11-24`
- Test: `tests/Unit/Notifications/NotifiableTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications;

use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Tests\TestCase;

class NotifiableTest extends TestCase
{
    public function test_routes_resolve_from_notification_channels_layer(): void
    {
        config([
            'shield.notification_channels.mail.to' => 'ops@example.com',
            'shield.notification_channels.slack.to' => 'https://hooks.slack.com/services/T/B/x',
            'shield.notification_channels.discord.webhook_url' => 'https://discord.com/api/webhooks/1/abc',
        ]);

        $notifiable = new Notifiable();

        $this->assertSame('ops@example.com', $notifiable->routeNotificationForMail());
        $this->assertSame('https://hooks.slack.com/services/T/B/x', $notifiable->routeNotificationForSlack());
        $this->assertSame('https://discord.com/api/webhooks/1/abc', $notifiable->routeNotificationForDiscord());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_routes_resolve_from_notification_channels_layer`
Expected: FAIL on the mail/slack assertions (current code reads `shield.notifications.mail.to` / `...slack.to`, which are unset, returning `null`).

- [ ] **Step 3: Fix the route methods**

Replace the three route methods in `src/Notifications/Notifiable.php`:

```php
    public function routeNotificationForMail()
    {
        return config('shield.notification_channels.mail.to');
    }

    public function routeNotificationForSlack()
    {
        return config('shield.notification_channels.slack.to'); // incoming webhook URL
    }

    public function routeNotificationForDiscord()
    {
        return config('shield.notification_channels.discord.webhook_url');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter test_routes_resolve_from_notification_channels_layer`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Notifiable.php tests/Unit/Notifications/NotifiableTest.php
git commit -m "fix(notifications): resolve Notifiable routes from notification_channels config"
```

---

## Task 3: Fix `DiscordMessage::toArray()` serialization bugs

Three bugs in [src/Notifications/Channels/Discord/DiscordMessage.php:114-135](../../src/Notifications/Channels/Discord/DiscordMessage.php#L114): (a) `hexdec($this->color)` when `$color` is `null` (no color method called), (b) `icon_url` reads `$this->footerImg`, a property that does not exist (it is `$this->footerUrl`), so the footer icon is always blank, (c) `timestamp ?? now()` emits a `Carbon` object instead of an ISO-8601 string.

**Files:**
- Modify: `src/Notifications/Channels/Discord/DiscordMessage.php` (add a default color constant; fix `toArray()`)
- Test: `tests/Unit/Notifications/Channels/Discord/DiscordMessageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications\Channels\Discord;

use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;
use OzanKurt\Shield\Tests\TestCase;

class DiscordMessageTest extends TestCase
{
    public function test_to_array_is_safe_without_a_color_method(): void
    {
        $embed = (new DiscordMessage)->title('Test')->toArray()['embeds'][0];

        // Default gray color, not hexdec(null).
        $this->assertSame(hexdec(DiscordMessage::COLOR_DEFAULT), $embed['color']);
    }

    public function test_footer_icon_uses_the_footer_url(): void
    {
        $embed = (new DiscordMessage)
            ->footer('Shield', 'https://example.com/icon.png')
            ->toArray()['embeds'][0];

        $this->assertSame('https://example.com/icon.png', $embed['footer']['icon_url']);
    }

    public function test_timestamp_defaults_to_an_iso8601_string(): void
    {
        $embed = (new DiscordMessage)->title('Test')->toArray()['embeds'][0];

        $this->assertIsString($embed['timestamp']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DiscordMessageTest`
Expected: FAIL. `test_footer_icon_uses_the_footer_url` fails (icon_url is `''` because `footerImg` is undefined), `test_timestamp_defaults_to_an_iso8601_string` fails (value is a Carbon instance), and `COLOR_DEFAULT` does not exist yet (the color test errors).

- [ ] **Step 3: Add the default color constant**

In `src/Notifications/Channels/Discord/DiscordMessage.php`, add alongside the existing color constants (after `COLOR_ERROR`):

```php
    public const COLOR_DEFAULT = '6c757d'; // gray, used when no severity color is set
```

- [ ] **Step 4: Fix `toArray()`**

Replace the `toArray()` method body:

```php
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'avatar_url' => $this->avatarUrl,
            'embeds' => [
                [
                    'title' => $this->title,
                    'url' => $this->url,
                    'type' => 'rich',
                    'description' => $this->description,
                    'fields' => $this->fields,
                    'color' => hexdec($this->color ?? self::COLOR_DEFAULT),
                    'footer' => [
                        'text' => $this->footer ?? '',
                        'icon_url' => $this->footerUrl ?? '',
                    ],
                    'timestamp' => $this->timestamp ?? now()->toIso8601String(),
                ],
            ],
        ];
    }
```

(Changes: `username` no longer falls back to the stale `'Laravel Backup'` literal, the typed property is always set; `color` defaults to `COLOR_DEFAULT`; `icon_url` reads `footerUrl`; `timestamp` defaults to an ISO-8601 string.)

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter DiscordMessageTest`
Expected: PASS (all three).

- [ ] **Step 6: Run the full suite to confirm no regressions**

Run: `vendor/bin/phpunit`
Expected: no new failures introduced by these changes.

- [ ] **Step 7: Commit**

```bash
git add src/Notifications/Channels/Discord/DiscordMessage.php tests/Unit/Notifications/Channels/Discord/DiscordMessageTest.php
git commit -m "fix(notifications): correct DiscordMessage color, footer icon, and timestamp serialization"
```

---

## Self-review

- **Spec coverage:** Covers spec-005 Section 9 items that the integrity card depends on: Slack dependency (Task 1), `Notifiable` config paths (Task 2), `DiscordMessage` bugs (Task 3). The Section 9 `via()`/`viaQueues()` repairs of the existing login/attack notifications are intentionally deferred (see "Out of scope") because the integrity notification ships its own correct `via()` in a later plan; this is recorded, not dropped.
- **Placeholder scan:** No TBD/TODO; every code step shows full code; every run step states the expected result.
- **Type consistency:** `COLOR_DEFAULT` is referenced in the test and defined in Step 3 before use in Step 4. Config keys (`notification_channels.mail.to`, `.slack.to`, `.discord.webhook_url`) match [config/shield.php:110-141](../../config/shield.php#L110).

## Remaining Phase 1 plans (roadmap, written next, not part of this plan's tasks)

1. **Data layer** - `ls_integrity_{runs,changes,baselines}` migrations + models + dedicated lookups (`ls_integrity_statuses`, `ls_integrity_change_types`, `ls_integrity_comparison_bases`) + seeding + new `ScannerTrigger` names. (spec sections 5, 5.2, 5.3)
2. **Engine** - `Manifest` (disk-aware streaming hash, incremental, canonical key, symlink/limit rules) + `Baseline` (signed artifact, scope fingerprint, atomic write, tamper vs corruption) + `IntegrityScanner` (lock, streaming diff, classify, chunked persist) + `WatchCommand` refactor onto `Manifest->hashes()` with the regression test. (spec sections 4, 6)
3. **Notification + commands** - `IntegrityScanCompletedEvent`/`Listener`/`Notification` (correct `via()`, bounded queries, per-channel limits, `SeverityColor`, translations), feature-local throttle + suppression rules, `shield:integrity`/`-bless`/`-status`/`-prune`/heartbeat commands, scheduler wiring. (spec sections 6.4-6.8, 7, 8.1-8.3)
4. **Dashboard + config + docs** - `IntegrityController` + routes + Bootstrap/DataTables views + the `integrity` config block + `notifications.integrity_changed` + `docs/integrity.md` + premium-gating note. (spec sections 8.4, 10, 11, 12)

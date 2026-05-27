# Audit log — Tamper-evident security audit trail

The `ls_audit_log` table captures admin/security-sensitive state changes. Every record stores an HMAC of its content + the previous record's HMAC, forming a chain. Modifying or deleting a record breaks the chain, and `shield:audit-verify` reports the first broken link.

## Event categories (all configurable on/off)

- `auth.login`, `auth.logout`, `auth.failed_login`, `auth.password_reset_*`
- `2fa.challenge_issued`, `2fa.verified`, `2fa.recovery_used` (when your auth stack emits these)
- `user.created`, `user.updated`, `user.deleted` (opt-in via `HasAuditLog` trait)
- `role.attached`, `role.detached`
- `model.<class>.<event>` (any model with `HasAuditLog` trait)
- `config.drift`, `file.drift`, `composer.changed`, `env.changed` (scanner-driven)
- `acl.added`, `acl.updated`, `acl.deleted`, `acl.expired`
- `scanner.started`, `scanner.completed`, `scanner.finding`, `scanner.quarantine`
- `threat_feed.sync_started`, `threat_feed.sync_completed`, `threat_feed.sync_failed`
- `notification.sent`
- `dashboard.action`
- `bypass.used`
- `shield.installed`, `shield.upgraded`

## Tamper evidence

Each record stores:
- `prev_hash` — the previous record's `hmac` (or null for the first row)
- `hmac` — HMAC-SHA256 of canonicalized record JSON + prev_hash, signed with `LS_AUDIT_HMAC_SECRET`

Modifying any field of any record changes its HMAC; subsequent records' HMACs are now misaligned with what `prev_hash` says they should be.

### Verify the chain

```bash
php artisan shield:audit-verify
```

Walks the chain in id order. Reports the first row whose computed HMAC doesn't match its stored HMAC, plus any inconsistencies with `prev_hash`.

### Rotate the secret

```bash
php artisan shield:audit-rotate-secret
```

Re-HMACs the entire chain with a new secret (read from `.env` after you change `LS_AUDIT_HMAC_SECRET`). Audit-logged.

## Programmatic logging

```php
use OzanKurt\Shield\Services\Audit\AuditLogger;

app(AuditLogger::class)->log('user.password_changed', 'User changed their password', [
    'severity' => 'medium',
    'subject_type' => User::class,
    'subject_id' => $user->id,
    'changes' => ['password_changed_at' => now()],
]);
```

`kind` not yet seeded? `AuditLogger` auto-creates the lookup row on first use, so you don't have to pre-seed every conceivable model event kind.

## Opt-in model auditing via `HasAuditLog`

```php
use OzanKurt\Shield\Concerns\HasAuditLog;

class User extends Authenticatable
{
    use HasAuditLog;

    // Optional overrides:
    public function auditLogChanges(): array
    {
        return $this->getDirty();  // default
    }

    public function auditLogShouldLog(string $event): bool
    {
        return $event !== 'updated' || $this->isDirty('email');
    }
}
```

## Retention

Default: 365 days. Override per kind:

```php
'audit_log' => [
    'retention_days' => [
        'default' => 365,
        'http.outbound' => 30,
        'auth.failed_login' => 730,
        'acl.*' => 1095,  // 3 years for ACL changes
    ],
],
```

`shield:audit-prune` (auto-scheduled) deletes records past retention.

## Remote sinks (1.0+)

Forward audit records to S3, syslog, webhook, or hosted endpoints in addition to the local DB:

```php
'audit_log' => [
    'sinks' => [
        'file' => ['enabled' => true, 'path' => 'storage/shield/audit', 'rotation' => 'daily'],
        'webhook' => ['enabled' => false, 'url' => env('LS_AUDIT_WEBHOOK_URL')],
    ],
],
```

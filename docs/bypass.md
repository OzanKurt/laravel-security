# Bypass — Admin Lockout Recovery

Three independent layers, all audit-logged when used. Pick the one that fits your situation; combine them for defense in depth.

## 1. Bypass key header

Set a random 32+ char value in `.env`:

```env
LS_BYPASS_KEY=YR4XzNGm91kS3VRYg7zaaeXq8KdYmJpw
```

Then send any request with the `X-Security-Bypass` header set to that value to skip ALL ACL / firewall / scoring checks:

```bash
curl -H "X-Security-Bypass: YR4XzNGm91kS3VRYg7zaaeXq8KdYmJpw" https://your-app.test
```

Audit-logged as `bypass.used` with `mechanism=header_key`.

**When to use:** you're locked out from an unexpected IP (mobile data, travel, etc.) and need urgent access.

## 2. Config IP whitelist (always-on, immutable from UI)

```env
LS_BYPASS_IPS=203.0.113.5,2001:db8::/32
```

Comma-separated list of IPs or CIDR ranges. These IPs ALWAYS bypass everything, can never be auto-blocked, and can't be removed from the dashboard — only by editing `.env`.

**When to use:** office static IPs, your home IP, dev/staging server IPs, infrastructure admin IPs.

## 3. Recovery Artisan command

```bash
php artisan shield:bypass-add 203.0.113.5
php artisan shield:bypass-list
php artisan shield:bypass-remove 203.0.113.5
```

Creates an ACL entry with `kind=ip, action=allow, source=bypass`. Requires shell access.

**When to use:** one-off "I'm stuck, let me in now" without editing `.env`. Especially useful from a deploy CI to bootstrap admin access on first deploy.

## What "bypass" actually skips

When any of the three layers fires:

- `firewall.acl` short-circuits (no blacklist/block enforcement)
- `firewall.live_traffic` still records (so the bypass usage is visible)
- Domain firewall middlewares (xss / sqli / lfi / etc.) **still run** — bypass doesn't disable WAF rule evaluation, only the IP-level ACL gate

If you need to bypass WAF rules too, attach `firewall.bypass` middleware to your route explicitly.

## Audit trail

Every bypass invocation creates an audit log entry:

```sql
SELECT * FROM ls_audit_log
WHERE kind_id = (SELECT id FROM ls_audit_log_kinds WHERE name = 'bypass.used')
ORDER BY id DESC LIMIT 50;
```

If you ever see unexpected `bypass.used` entries, rotate `LS_BYPASS_KEY` immediately.

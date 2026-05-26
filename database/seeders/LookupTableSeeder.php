<?php

namespace OzanKurt\Shield\Database\Seeders;

use Illuminate\Database\Seeder;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Models\Lookups\ActionKind;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Models\Lookups\AuthEventKind;
use OzanKurt\Shield\Models\Lookups\LogKind;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\WafRuleAction;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\Lookups\WafRuleKind;
use OzanKurt\Shield\Models\Lookups\WafRuleTarget;

class LookupTableSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAclKinds();
        $this->seedAclActions();
        $this->seedLogLevels();
        $this->seedLogKinds();
        $this->seedAuthEventKinds();
        $this->seedAuditLogKinds();
        $this->seedActionKinds();
        $this->seedWafRuleCategories();
        $this->seedWafRuleKinds();
        $this->seedWafRuleTargets();
        $this->seedWafRuleActions();
    }

    private function seedAclKinds(): void
    {
        $kinds = [
            ['ip', 'IP address', 'A single IPv4/IPv6 address', 10],
            ['cidr', 'CIDR range', 'IP range in CIDR notation', 20],
            ['asn', 'ASN', 'Autonomous System Number (e.g. AS12345)', 30],
            ['country', 'Country', 'ISO 3166-1 alpha-2 country code', 40],
            ['region', 'Region', 'Country subdivision name', 50],
            ['city', 'City', 'City name', 60],
            ['hostname', 'Hostname', 'Reverse-DNS hostname', 70],
            ['ua_regex', 'User-Agent regex', 'PHP-compatible regex matched against User-Agent', 80],
            ['ref_regex', 'Referrer regex', 'PHP-compatible regex matched against Referer header', 90],
        ];

        foreach ($kinds as [$name, $label, $description, $sort]) {
            AclKind::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedAclActions(): void
    {
        $actions = [
            ['allow', 'Allow', 'Bypass all subsequent firewall checks', 10],
            ['blacklist', 'Blacklist', 'Permanent block (never expires)', 20],
            ['block', 'Block', 'Temporary block (usually has expires_at)', 30],
        ];

        foreach ($actions as [$name, $label, $description, $sort]) {
            AclAction::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedLogLevels(): void
    {
        $levels = [
            ['low', 'Low', 'Informational events', 10],
            ['medium', 'Medium', 'Standard attack patterns', 20],
            ['high', 'High', 'Multiple attack patterns or known exploit kit', 30],
            ['critical', 'Critical', 'Confirmed compromise or active exploitation', 40],
        ];

        foreach ($levels as [$name, $label, $description, $sort]) {
            LogLevel::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedLogKinds(): void
    {
        $kinds = [
            ['ip', 'IP block hit'],
            ['agent', 'User-Agent filter hit'],
            ['bot', 'Bot filter hit'],
            ['geo', 'Geographic filter hit'],
            ['lfi', 'Local file inclusion'],
            ['rfi', 'Remote file inclusion'],
            ['php', 'PHP wrapper protocol'],
            ['referrer', 'Blocked referrer'],
            ['session', 'Session deserialization'],
            ['sqli', 'SQL injection'],
            ['swear', 'Profanity filter'],
            ['url', 'URL pattern match'],
            ['whitelist', 'Whitelist violation'],
            ['xss', 'Cross-site scripting'],
            ['keyword', 'Path keyword'],
            ['honeypot', 'Honeypot route hit'],
            ['scoring', 'Suspicion score threshold'],
            ['https_enforce', 'HTTPS enforcement redirect'],
            ['disabled_route', 'Disabled route hit'],
            ['headers', 'Security headers applied'],
        ];

        $i = 10;
        foreach ($kinds as [$name, $label]) {
            LogKind::updateOrCreate(['name' => $name], [
                'label' => $label,
                'sort_order' => $i,
            ]);
            $i += 10;
        }
    }

    private function seedAuthEventKinds(): void
    {
        $kinds = [
            ['login', 'Login success'],
            ['logout', 'Logout'],
            ['failed_login', 'Failed login attempt'],
            ['password_reset_requested', 'Password reset requested'],
            ['password_reset_completed', 'Password reset completed'],
            ['2fa_challenge_issued', '2FA challenge issued'],
            ['2fa_verified', '2FA verified'],
            ['2fa_recovery_used', '2FA recovery code used'],
        ];

        $i = 10;
        foreach ($kinds as [$name, $label]) {
            AuthEventKind::updateOrCreate(['name' => $name], [
                'label' => $label,
                'sort_order' => $i,
            ]);
            $i += 10;
        }
    }

    private function seedAuditLogKinds(): void
    {
        $kinds = [
            'auth.login', 'auth.logout', 'auth.failed_login', 'auth.password_reset',
            'user.created', 'user.updated', 'user.deleted',
            // Explicit model-event kinds used by HasAuditLog trait
            'model.user.created', 'model.user.updated', 'model.user.deleted',
            'role.attached', 'role.detached',
            'config.drift', 'file.drift', 'composer.changed', '.env.changed',
            'acl.added', 'acl.updated', 'acl.deleted', 'acl.expired',
            'scanner.started', 'scanner.completed', 'scanner.finding', 'scanner.quarantine',
            'threat_feed.sync_started', 'threat_feed.sync_completed', 'threat_feed.sync_failed',
            'notification.sent',
            'dashboard.action',
            'http.outbound',
            'bypass.used',
            'shield.installed', 'shield.upgraded',
        ];

        $i = 10;
        foreach ($kinds as $name) {
            AuditLogKind::updateOrCreate(['name' => $name], [
                'label' => $this->humanize($name),
                'sort_order' => $i,
            ]);
            $i += 10;
        }
    }

    private function seedActionKinds(): void
    {
        $actions = [
            ['passed', 'Request passed'],
            ['blocked', 'Request blocked'],
            ['whitelisted', 'Whitelisted bypass'],
            ['redirected', 'Redirected'],
            ['throttled', 'Rate limited'],
        ];

        $i = 10;
        foreach ($actions as [$name, $label]) {
            ActionKind::updateOrCreate(['name' => $name], [
                'label' => $label,
                'sort_order' => $i,
            ]);
            $i += 10;
        }
    }

    private function seedWafRuleCategories(): void
    {
        $cats = ['xss', 'sqli', 'lfi', 'rfi', 'php_protocols', 'session', 'agent', 'bot', 'keyword', 'custom'];
        $i = 10;
        foreach ($cats as $name) {
            WafRuleCategory::updateOrCreate(['name' => $name], [
                'label' => strtoupper($name),
                'sort_order' => $i,
            ]);
            $i += 10;
        }
    }

    private function seedWafRuleKinds(): void
    {
        $kinds = ['regex', 'header_match', 'ip_match', 'ua_match'];
        $i = 10;
        foreach ($kinds as $name) {
            WafRuleKind::updateOrCreate(['name' => $name], [
                'label' => $this->humanize($name),
                'sort_order' => $i,
            ]);
            $i += 10;
        }
    }

    private function seedWafRuleTargets(): void
    {
        $targets = ['request_input', 'request_url', 'request_path', 'request_query', 'request_header', 'request_body', 'full_request'];
        $i = 10;
        foreach ($targets as $name) {
            WafRuleTarget::updateOrCreate(['name' => $name], [
                'label' => $this->humanize($name),
                'sort_order' => $i,
            ]);
            $i += 10;
        }
    }

    private function seedWafRuleActions(): void
    {
        $actions = [
            ['block', 'Block', 'Return immediate 403'],
            ['log', 'Log only', 'Record but allow through'],
            ['score', 'Score', 'Contribute to suspicion score'],
        ];
        $i = 10;
        foreach ($actions as [$name, $label, $description]) {
            WafRuleAction::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $i,
            ]);
            $i += 10;
        }
    }

    private function humanize(string $name): string
    {
        return ucwords(str_replace(['_', '.'], ' ', $name));
    }
}

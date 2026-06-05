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
use OzanKurt\Shield\Models\Lookups\ScannerBackend;
use OzanKurt\Shield\Models\Lookups\ScannerFindingStatus;
use OzanKurt\Shield\Models\Lookups\ScannerStatus;
use OzanKurt\Shield\Models\Lookups\ScannerTarget;
use OzanKurt\Shield\Models\Lookups\ScannerTrigger;
use OzanKurt\Shield\Models\Lookups\SignatureCategory;
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
        $this->seedSignatureCategories();
        $this->seedScannerTargets();
        $this->seedScannerBackends();
        $this->seedScannerStatuses();
        $this->seedScannerFindingStatuses();
        $this->seedScannerTriggers();
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
            'threat_feed.sync_started', 'threat_feed.sync_completed', 'threat_feed.sync_failed', 'threat_feed.sync_skipped',
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

    private function seedSignatureCategories(): void
    {
        $cats = [
            ['malware', 'Malware', 'Generic malicious code', 10],
            ['backdoor', 'Backdoor', 'Unauthorized remote access mechanism', 20],
            ['webshell', 'Web Shell', 'PHP/ASP web shell', 30],
            ['phishing', 'Phishing', 'Credential-harvesting or deceptive pages', 40],
            ['heuristic', 'Heuristic', 'Suspicious pattern without definitive match', 50],
        ];

        foreach ($cats as [$name, $label, $description, $sort]) {
            SignatureCategory::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedScannerTargets(): void
    {
        $targets = [
            ['vendor', 'Vendor directory', 'Composer vendor/ directory', 10],
            ['app_files', 'Application files', 'app/, routes/, config/ PHP files', 20],
            ['public_uploads', 'Public uploads', 'Publicly accessible uploaded files', 30],
            ['recently_modified', 'Recently modified', 'Files modified in the last N hours', 40],
            ['config_drift', 'Config drift', 'Config files compared against baseline', 50],
            ['env_audit', '.env audit', 'Presence and safety check of .env files', 60],
            ['dotfiles', 'Dot files', 'Hidden/dot files in web root', 70],
            ['db_content', 'Database content', 'Scan selected DB columns for patterns', 80],
            ['unknown_files', 'Unknown files', 'Files not tracked by composer or git', 90],
        ];

        foreach ($targets as [$name, $label, $description, $sort]) {
            ScannerTarget::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedScannerBackends(): void
    {
        $backends = [
            ['native', 'Native', 'Built-in PHP regex/hash/string signature matching', 10],
            ['clamav', 'ClamAV', 'ClamAV daemon via xenolope/quahog socket', 20],
            ['composer_audit', 'Composer Audit', 'composer audit --format=json vulnerability scan', 30],
        ];

        foreach ($backends as [$name, $label, $description, $sort]) {
            ScannerBackend::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedScannerStatuses(): void
    {
        $statuses = [
            ['queued', 'Queued', 'Waiting to start', 10],
            ['running', 'Running', 'Scan currently in progress', 20],
            ['completed', 'Completed', 'Scan finished successfully', 30],
            ['failed', 'Failed', 'Scan terminated due to error', 40],
            ['cancelled', 'Cancelled', 'Scan cancelled by user', 50],
        ];

        foreach ($statuses as [$name, $label, $description, $sort]) {
            ScannerStatus::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedScannerFindingStatuses(): void
    {
        $statuses = [
            ['open', 'Open', 'Finding not yet acted upon', 10],
            ['quarantined', 'Quarantined', 'File moved to quarantine', 20],
            ['resolved', 'Resolved', 'Finding resolved / cleaned', 30],
            ['ignored', 'Ignored', 'Intentionally ignored', 40],
            ['false_positive', 'False Positive', 'Marked as not a real threat', 50],
        ];

        foreach ($statuses as [$name, $label, $description, $sort]) {
            ScannerFindingStatus::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedScannerTriggers(): void
    {
        $triggers = [
            ['manual', 'Manual', 'Triggered by user or CLI', 10],
            ['scheduled', 'Scheduled', 'Triggered by cron schedule', 20],
            ['file_change', 'File Change', 'Triggered by file watcher event', 30],
            ['webhook', 'Webhook', 'Triggered by external webhook call', 40],
        ];

        foreach ($triggers as [$name, $label, $description, $sort]) {
            ScannerTrigger::updateOrCreate(['name' => $name], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $sort,
            ]);
        }
    }

    private function humanize(string $name): string
    {
        return ucwords(str_replace(['_', '.'], ' ', $name));
    }
}

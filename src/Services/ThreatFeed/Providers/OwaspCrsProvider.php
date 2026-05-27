<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Providers;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\WafRuleAction;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\Lookups\WafRuleKind;
use OzanKurt\Shield\Models\Lookups\WafRuleTarget;
use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;

/**
 * Pulls a curated subset of OWASP ModSecurity Core Rule Set patterns and
 * converts them into ls_waf_rules with source=owasp_crs.
 *
 * The OWASP CRS .conf format isn't directly portable to our regex engine;
 * for v1.1 we pull a hand-curated JSON manifest from a public mirror.
 * If the manifest isn't reachable, we ship an embedded fallback.
 */
class OwaspCrsProvider implements ThreatFeedProvider
{
    private const MANIFEST_URL = 'https://raw.githubusercontent.com/OzanKurt/laravel-shield-signatures/main/owasp-crs/rules.json';

    public function __construct(private LookupResolver $lookups) {}

    public function name(): string { return 'owasp_crs'; }
    public function label(): string { return 'OWASP ModSecurity CRS'; }

    public function isAvailable(): bool
    {
        return (bool) config('shield.threat_feed.owasp_crs.enabled', true);
    }

    public function sync(): SyncResult
    {
        $rules = $this->fetchRules();
        if (empty($rules)) {
            $rules = $this->embeddedFallback();
        }

        $imported = 0;
        $updated = 0;

        foreach ($rules as $rule) {
            $existing = WafRule::query()
                ->where(['source' => 'owasp_crs', 'source_ref' => $rule['ref'] ?? null])
                ->first();

            $payload = [
                'source' => 'owasp_crs',
                'source_ref' => $rule['ref'] ?? '',
                'name' => $rule['name'] ?? 'OWASP CRS rule',
                'description' => $rule['description'] ?? null,
                'category_id' => $this->lookups->id(WafRuleCategory::class, $rule['category'] ?? 'custom'),
                'kind_id' => $this->lookups->id(WafRuleKind::class, $rule['kind'] ?? 'regex'),
                'target_id' => $this->lookups->id(WafRuleTarget::class, $rule['target'] ?? 'request_input'),
                'pattern' => $rule['pattern'] ?? '',
                'action_id' => $this->lookups->id(WafRuleAction::class, $rule['action'] ?? 'block'),
                'severity_id' => $this->lookups->id(LogLevel::class, $rule['severity'] ?? 'high'),
                'score' => $rule['score'] ?? 10,
                'is_enabled' => true,
                'version' => (int) ($rule['version'] ?? 1),
            ];

            if ($existing) {
                if ($existing->version < $payload['version']) {
                    $existing->update($payload);
                    $updated++;
                }
            } else {
                WafRule::create($payload);
                $imported++;
            }
        }

        return new SyncResult($this->name(), imported: $imported, updated: $updated);
    }

    private function fetchRules(): array
    {
        try {
            $response = Http::timeout(15)->get(self::MANIFEST_URL);
            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data)) return $data;
            }
        } catch (\Throwable) {
            // fall through
        }
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function embeddedFallback(): array
    {
        return [
            ['ref' => 'crs-921110', 'name' => 'HTTP Request Smuggling',         'category' => 'custom', 'pattern' => '/(?:%0a|%0d|%00)\s*(?:HTTP\/|Host:)/i', 'severity' => 'critical', 'version' => 1],
            ['ref' => 'crs-941100', 'name' => 'XSS Attack via libinjection',     'category' => 'xss',    'pattern' => '/<script\b[^>]*>[\s\S]*?<\/script\s*>/i', 'severity' => 'critical', 'version' => 1],
            ['ref' => 'crs-942100', 'name' => 'SQL Injection via libinjection',  'category' => 'sqli',   'pattern' => '/(?:union\s+select|;\s*drop\s+table|--\s*$)/i', 'severity' => 'critical', 'version' => 1],
            ['ref' => 'crs-930100', 'name' => 'Path Traversal Attack',           'category' => 'lfi',    'pattern' => '/(?:\.\./|\.\.\\\\){2,}/', 'severity' => 'high', 'version' => 1],
            ['ref' => 'crs-933100', 'name' => 'PHP open-tag injection',          'category' => 'php_protocols', 'pattern' => '/<\?(?:php|=)?/i', 'severity' => 'high', 'version' => 1],
        ];
    }
}

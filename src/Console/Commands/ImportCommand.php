<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\WafRuleAction;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\Lookups\WafRuleKind;
use OzanKurt\Shield\Models\Lookups\WafRuleTarget;
use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class ImportCommand extends Command
{
    protected $signature = 'shield:import {file} {--dry-run : Preview without writing}';
    protected $description = 'Import ACL + user-defined WAF rules from a shield:export JSON file.';

    public function handle(LookupResolver $lookups): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            $this->error('Invalid JSON.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = ['acl_imported' => 0, 'rules_imported' => 0];

        foreach ($payload['acl'] ?? [] as $entry) {
            if ($dryRun) { $stats['acl_imported']++; continue; }

            Acl::updateOrCreate(
                [
                    'kind_id' => $lookups->id(AclKind::class, $entry['kind']),
                    'value' => $entry['value'],
                ],
                [
                    'action_id' => $lookups->id(AclAction::class, $entry['action']),
                    'source' => $entry['source'] ?? 'import',
                    'reason' => $entry['reason'] ?? null,
                    'expires_at' => ! empty($entry['expires_at']) ? $entry['expires_at'] : null,
                    'meta' => $entry['meta'] ?? null,
                ],
            );
            $stats['acl_imported']++;
        }

        foreach ($payload['waf_rules_user'] ?? [] as $rule) {
            if ($dryRun) { $stats['rules_imported']++; continue; }

            WafRule::updateOrCreate(
                ['source' => 'user', 'name' => $rule['name']],
                [
                    'description' => $rule['description'] ?? null,
                    'category_id' => $lookups->id(WafRuleCategory::class, $rule['category']),
                    'kind_id' => $lookups->id(WafRuleKind::class, $rule['kind']),
                    'target_id' => $lookups->id(WafRuleTarget::class, $rule['target']),
                    'pattern' => $rule['pattern'] ?? '',
                    'action_id' => $lookups->id(WafRuleAction::class, $rule['action']),
                    'severity_id' => $lookups->id(LogLevel::class, $rule['severity']),
                    'score' => $rule['score'] ?? 0,
                    'is_enabled' => $rule['is_enabled'] ?? true,
                    'version' => 1,
                ],
            );
            $stats['rules_imported']++;
        }

        $this->info(($dryRun ? '[dry run] ' : '') . "Imported {$stats['acl_imported']} ACL entries, {$stats['rules_imported']} user WAF rules");
        return self::SUCCESS;
    }
}

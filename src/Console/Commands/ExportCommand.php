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

class ExportCommand extends Command
{
    protected $signature = 'shield:export {file=shield-export.json}';
    protected $description = 'Export ACL entries + user-defined WAF rules + config to a JSON file.';

    public function handle(LookupResolver $lookups): int
    {
        $path = (string) $this->argument('file');

        $payload = [
            'schema_version' => '1.0',
            'exported_at' => now()->toIso8601String(),
            'config' => config('shield'),
            'acl' => Acl::query()->get()->map(fn ($a) => [
                'kind' => $lookups->name(AclKind::class, $a->kind_id),
                'value' => $a->value,
                'action' => $lookups->name(AclAction::class, $a->action_id),
                'source' => $a->source,
                'reason' => $a->reason,
                'expires_at' => (string) $a->expires_at,
                'meta' => $a->meta,
            ])->all(),
            'waf_rules_user' => WafRule::query()->where('source', 'user')->get()->map(fn ($r) => [
                'name' => $r->name,
                'description' => $r->description,
                'category' => $lookups->name(WafRuleCategory::class, $r->category_id),
                'kind' => $lookups->name(WafRuleKind::class, $r->kind_id),
                'target' => $lookups->name(WafRuleTarget::class, $r->target_id),
                'pattern' => $r->pattern,
                'action' => $lookups->name(WafRuleAction::class, $r->action_id),
                'severity' => $lookups->name(LogLevel::class, $r->severity_id),
                'score' => $r->score,
                'is_enabled' => $r->is_enabled,
            ])->all(),
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Exported to {$path}");

        return self::SUCCESS;
    }
}

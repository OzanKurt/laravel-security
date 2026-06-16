<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class BypassListCommand extends Command
{
    protected $signature = 'shield:bypass-list';
    protected $description = 'List all IPs in the bypass ACL (source=bypass, action=allow)';

    public function handle(LookupResolver $lookups): int
    {
        $allowId = $lookups->id(AclAction::class, 'allow');

        $entries = Acl::query()
            ->where('source', 'bypass')
            ->where('action_id', $allowId)
            ->orderBy('id')
            ->get(['id', 'value', 'reason', 'created_at']);

        if ($entries->isEmpty()) {
            $this->info('No bypass ACL entries found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'IP', 'Reason', 'Created At'],
            $entries->map(fn ($e) => [
                $e->id,
                $e->value,
                $e->reason ?? '-',
                $e->created_at?->toDateTimeString() ?? '-',
            ])->toArray(),
        );

        return self::SUCCESS;
    }
}

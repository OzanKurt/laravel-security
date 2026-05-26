<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class BypassRemoveCommand extends Command
{
    protected $signature = 'shield:bypass-remove {ip}';
    protected $description = 'Remove an IP from the bypass ACL list (source=bypass, action=allow)';

    public function handle(LookupResolver $lookups): int
    {
        $ip = $this->argument('ip');

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error("Not a valid IP: $ip");
            return self::FAILURE;
        }

        $allowId = $lookups->id(AclAction::class, 'allow');

        $entries = Acl::query()
            ->where('value', $ip)
            ->where('source', 'bypass')
            ->where('action_id', $allowId)
            ->get();

        if ($entries->isEmpty()) {
            $this->warn("No bypass ACL entry found for IP: $ip");
            return self::FAILURE;
        }

        $count = $entries->count();
        $entries->each(fn ($entry) => $entry->delete());

        $this->info("Removed {$count} bypass ACL entry/entries for IP: $ip");
        return self::SUCCESS;
    }
}

<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class BypassAddCommand extends Command
{
    protected $signature = 'shield:bypass-add {ip}';
    protected $description = 'Add an IP to the ACL with action=allow + source=bypass (recovery)';

    public function handle(LookupResolver $lookups): int
    {
        $ip = $this->argument('ip');

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error("Not a valid IP: $ip");
            return self::FAILURE;
        }

        Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'allow'),
            'value' => $ip,
            'source' => 'bypass',
            'reason' => 'Added via shield:bypass-add CLI command',
        ]);

        $this->info("Added $ip to bypass list (kind=ip, action=allow, source=bypass).");
        return self::SUCCESS;
    }
}

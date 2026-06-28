<?php

namespace OzanKurt\Shield\Services\Honeypot;

use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

/**
 * Shared honeypot trap: audit-log the hit, auto-block the source IP in the
 * ACL (source=honeypot), and 404. Used by both the route controller and the
 * regex middleware so behaviour is identical.
 */
class HoneypotTrap
{
    public function __construct(
        private LookupResolver $lookups,
        private AuditLogger $audit,
    ) {}

    public function handle(Request $request, string $matchedPath): void
    {
        $ip = $request->ip();
        $blockDuration = (int) config('shield.honeypot.block_duration', 86400);

        $this->audit->log('acl.added', "Honeypot hit: {$matchedPath}", [
            'severity' => 'high',
            'ip' => $ip,
            'url' => $request->fullUrl(),
            'meta' => ['honeypot_path' => $matchedPath, 'block_seconds' => $blockDuration],
        ]);

        $existing = Acl::query()
            ->where('value', $ip)
            ->where('kind_id', $this->lookups->id(AclKind::class, 'ip'))
            ->where('source', 'honeypot')
            ->first();

        if (! $existing) {
            Acl::create([
                'kind_id' => $this->lookups->id(AclKind::class, 'ip'),
                'action_id' => $this->lookups->id(AclAction::class, 'block'),
                'value' => $ip,
                'source' => 'honeypot',
                'reason' => "Hit honeypot path: {$matchedPath}",
                'expires_at' => now()->addSeconds($blockDuration),
            ]);
        }

        abort(404);
    }
}

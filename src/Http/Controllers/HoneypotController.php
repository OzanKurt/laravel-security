<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class HoneypotController extends Controller
{
    public function trap(Request $request, LookupResolver $lookups, AuditLogger $audit)
    {
        $ip = $request->ip();
        $path = $request->path();
        $blockDuration = (int) config('shield.honeypot.block_duration', 86400);

        $audit->log('acl.added', "Honeypot hit: {$path}", [
            'severity' => 'high',
            'ip' => $ip,
            'url' => $request->fullUrl(),
            'meta' => ['honeypot_path' => $path, 'block_seconds' => $blockDuration],
        ]);

        // Auto-block the IP for the configured duration unless already blocked
        $existing = Acl::query()
            ->where('value', $ip)
            ->where('kind_id', $lookups->id(AclKind::class, 'ip'))
            ->where('source', 'honeypot')
            ->first();

        if (! $existing) {
            Acl::create([
                'kind_id' => $lookups->id(AclKind::class, 'ip'),
                'action_id' => $lookups->id(AclAction::class, 'block'),
                'value' => $ip,
                'source' => 'honeypot',
                'reason' => "Hit honeypot path: {$path}",
                'expires_at' => now()->addSeconds($blockDuration),
            ]);
        }

        abort(404);
    }
}

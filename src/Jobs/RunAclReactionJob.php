<?php

namespace OzanKurt\Shield\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Reactions\ReactionManager;

class RunAclReactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public string $reactionName,
        public int $aclId,
        public string $op, // 'ban' | 'unban'
    ) {}

    public function handle(ReactionManager $manager, AuditLogger $audit): void
    {
        $reaction = $manager->get($this->reactionName);
        $acl = Acl::find($this->aclId);

        if ($reaction === null || $acl === null || ! $reaction->isEnabled()) {
            return;
        }

        if ($this->op === 'ban') {
            if (! $manager->sourceAllowed($acl) || ! $reaction->appliesTo($acl)) {
                return;
            }
            $reaction->ban($acl);
        } else {
            $reaction->unban($acl);
        }

        $kind = $this->reactionName === 'cloudflare' ? 'reaction.cloudflare' : 'reaction.abuseipdb';
        $audit->log($kind, ucfirst($this->op) . " via {$this->reactionName}: {$acl->value}", [
            'severity' => 'low',
            'ip' => (string) $acl->value,
            'meta' => ['op' => $this->op, 'acl_id' => $acl->getKey()],
        ]);
    }
}

<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\Reactions\ReactionManager;

/**
 * Removes Cloudflare edge rules for ACL blocks that are no longer active.
 * ACL expiry is passive (the evaluator just ignores expired rows), so this
 * scheduled command is the only thing that reverses edge state. Bounded per
 * run by shield.reactions.reconcile_batch.
 */
class ReactionsReconcileCommand extends Command
{
    protected $signature = 'shield:reactions-reconcile';

    protected $description = 'Remove edge (Cloudflare) rules for expired/removed ACL blocks';

    public function handle(ReactionManager $manager): int
    {
        $batch = (int) config('shield.reactions.reconcile_batch', 200);

        // Rows that still carry a cloudflare rule id but are no longer an
        // active block: expired, OR soft-deleted (withTrashed catches those).
        // The rule_id check is pushed into SQL (json_extract IS NOT NULL) so
        // the batch limit only counts actionable orphans. Expired ACL rows are
        // never deleted, just expired, so filtering rule_id in PHP after the
        // limit could fill an entire batch with already-reconciled rows and
        // never reach real orphans.
        $rows = Acl::withTrashed()
            ->whereNotNull('meta->reactions->cloudflare->rule_id')
            ->where(function ($q) {
                $q->whereNotNull('deleted_at')
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('expires_at')->where('expires_at', '<=', now());
                  });
            })
            ->limit($batch)
            ->get();

        foreach ($rows as $acl) {
            $manager->onUnblock($acl);
        }

        $this->info("Reconciled {$rows->count()} edge rule(s).");

        return self::SUCCESS;
    }
}

<?php

namespace OzanKurt\Shield\Listeners;

use OzanKurt\Shield\Events\IntegrityScanCompletedEvent;
use OzanKurt\Shield\Notifications\IntegrityScanCompletedNotification;
use OzanKurt\Shield\Notifications\Notifiable;
use Throwable;

/**
 * Sends the integrity summary card. Thin by design (no ListenerHelper: integrity
 * scans run in the console with no HTTP request / auth-log context).
 *
 * Suppression: when suppress_when_no_changes is on and the per-run delta is
 * empty, ordinary completed runs are silent. Security/operational state events
 * (tamper_suspected, baseline_established, failed, aborted_limit) ALWAYS notify,
 * regardless of the delta.
 */
class IntegrityScanCompletedListener
{
    private const SECURITY_EVENT_STATUSES = [
        'tamper_suspected',
        'baseline_established',
        'failed',
        'aborted_limit',
    ];

    public function handle(IntegrityScanCompletedEvent $event): void
    {
        if (! config('shield.notifications.integrity_changed.enabled', false)) {
            return;
        }

        $run = $event->run;
        $status = $run->status->name ?? '';
        $isSecurityEvent = in_array($status, self::SECURITY_EVENT_STATUSES, true);

        $delta = (int) $run->count_new + (int) $run->count_modified + (int) $run->count_deleted;
        $suppress = (bool) config('shield.integrity.notify.suppress_when_no_changes', true);

        if (! $isSecurityEvent && $suppress && $delta === 0) {
            return;
        }

        try {
            (new Notifiable)->notify(new IntegrityScanCompletedNotification($run));
        } catch (Throwable $e) {
            report($e);
        }
    }
}

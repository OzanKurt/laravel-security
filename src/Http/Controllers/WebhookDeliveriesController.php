<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use OzanKurt\Shield\Jobs\ForwardAuditToCentralJob;
use OzanKurt\Shield\Models\AuditLog;
use OzanKurt\Shield\Models\WebhookDelivery;

class WebhookDeliveriesController extends Controller
{
    /**
     * Render the webhook deliveries dashboard. Shows recent outbound
     * calls to Central with status filter + retry button per row.
     */
    public function index(Request $request)
    {
        $query = WebhookDelivery::query()->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($operation = $request->query('operation')) {
            $query->where('operation', $operation);
        }

        $deliveries = $query->paginate(50)->withQueryString();

        $stats = [
            'total_24h' => WebhookDelivery::query()->where('dispatched_at', '>=', now()->subDay())->count(),
            'success_24h' => WebhookDelivery::query()->where('dispatched_at', '>=', now()->subDay())->where('status', 'success')->count(),
            'failure_24h' => WebhookDelivery::query()->where('dispatched_at', '>=', now()->subDay())->where('status', 'failure')->count(),
            'exhausted_24h' => WebhookDelivery::query()->where('dispatched_at', '>=', now()->subDay())->where('status', 'exhausted')->count(),
        ];

        return view('shield::dashboard.webhook-deliveries.index', compact('deliveries', 'stats'));
    }

    /**
     * Retry a single exhausted/failed delivery. Re-dispatches the
     * underlying ForwardAuditToCentralJob with the source audit log
     * payload reconstructed from the linked audit_log_id row.
     */
    public function retry(Request $request, int $id): RedirectResponse
    {
        $delivery = WebhookDelivery::query()->findOrFail($id);

        if ($delivery->isPending()) {
            return back()->with('status', "Delivery #{$id} is still pending — wait for it to finish.");
        }

        if ($delivery->operation !== 'webhook_ingest') {
            return back()->with('status', "Only webhook_ingest deliveries can be retried from this UI (use shield:heartbeat for heartbeat retries).");
        }

        if (! $delivery->audit_log_id) {
            return back()->with('status', "Delivery #{$id} has no linked audit log — cannot reconstruct payload for retry.");
        }

        $entry = AuditLog::query()->find($delivery->audit_log_id);
        if (! $entry) {
            return back()->with('status', "Audit log #{$delivery->audit_log_id} no longer exists — retry impossible.");
        }

        ForwardAuditToCentralJob::dispatch([
            'kind' => optional($entry->kind)->name ?? 'unknown',
            'severity' => optional($entry->severity)->name ?? 'medium',
            'description' => $entry->description,
            'actor_type' => $entry->actor_type,
            'actor_id' => $entry->actor_id,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'ip' => $entry->ip,
            'user_agent' => $entry->user_agent,
            'url' => $entry->url,
            'changes' => $entry->changes,
            'meta' => $entry->meta,
            'correlation_id' => $entry->correlation_id,
            'occurred_at' => $entry->created_at?->toIso8601String(),
            'audit_log_id' => $entry->id,
            'audit_log_uuid' => $entry->uuid ?? null,
        ])->onQueue((string) config('shield.premium.queue', 'default'));

        return back()->with('status', "Delivery #{$id} re-queued for retry.");
    }
}

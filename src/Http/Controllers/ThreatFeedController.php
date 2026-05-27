<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Models\AuditLog;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class ThreatFeedController extends Controller
{
    /**
     * @param iterable<ThreatFeedProvider> $providers
     */
    public function index(iterable $providers, LookupResolver $lookups)
    {
        $rows = [];

        $startedId = $lookups->id(AuditLogKind::class, 'threat_feed.sync_started');
        $completedId = $lookups->id(AuditLogKind::class, 'threat_feed.sync_completed');
        $failedId = $lookups->id(AuditLogKind::class, 'threat_feed.sync_failed');

        foreach ($providers as $provider) {
            $latest = AuditLog::query()
                ->whereIn('kind_id', array_filter([$startedId, $completedId, $failedId]))
                ->where('description', 'like', "%{$provider->name()}%")
                ->latest('id')
                ->first();

            $rows[] = [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'available' => $provider->isAvailable(),
                'last_run' => (string) ($latest?->created_at ?? '—'),
                'last_status' => $latest?->description ?? '—',
            ];
        }

        return view('shield::dashboard.threat-feed.index', compact('rows'));
    }
}

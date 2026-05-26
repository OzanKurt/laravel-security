<?php

namespace OzanKurt\Shield\Contracts;

interface ThreatFeedProvider
{
    /** Unique provider name (slug). */
    public function name(): string;

    /** Human-readable label for dashboard display. */
    public function label(): string;

    /** Whether this provider is configured + reachable. */
    public function isAvailable(): bool;

    /**
     * Pull from the upstream feed and apply changes to local tables
     * (typically ls_acl and/or ls_waf_rules). Returns a result object.
     */
    public function sync(): \OzanKurt\Shield\Services\ThreatFeed\SyncResult;
}

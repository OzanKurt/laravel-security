<?php

namespace OzanKurt\Shield\Contracts;

use OzanKurt\Shield\Models\Acl;

interface AclReaction
{
    /** Stable machine name, e.g. 'cloudflare' or 'abuseipdb_report'. */
    public function name(): string;

    /** True when configured + credentials present. */
    public function isEnabled(): bool;

    /** Reaction-specific applicability (kind=ip, public IP, not already done...). */
    public function appliesTo(Acl $acl): bool;

    /** Perform the outbound side effect for a new block. */
    public function ban(Acl $acl): void;

    /** Reverse the side effect (no-op for one-shot reactions). */
    public function unban(Acl $acl): void;
}

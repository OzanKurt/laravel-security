<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;

class AsnMatcher implements AclMatcher
{
    /**
     * Stub for beta.1 — full implementation lands when ASN/GeoIP support arrives.
     * Always returns false so ASN-kinded ACL entries are no-ops until then.
     */
    public function matches(Request $request, string $value): bool
    {
        return false;
    }
}

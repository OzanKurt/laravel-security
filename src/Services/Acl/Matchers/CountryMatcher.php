<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;

class CountryMatcher implements AclMatcher
{
    /**
     * Stub for beta.1 — full implementation lands when GeoIP support arrives.
     * Always returns false so country-kinded ACL entries are no-ops until then.
     */
    public function matches(Request $request, string $value): bool
    {
        return false;
    }
}

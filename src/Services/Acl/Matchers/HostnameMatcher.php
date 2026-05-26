<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;

class HostnameMatcher implements AclMatcher
{
    /**
     * Stub for beta.1 — full implementation lands in a later release.
     * Always returns false so hostname-kinded ACL entries are no-ops until then.
     */
    public function matches(Request $request, string $value): bool
    {
        return false;
    }
}

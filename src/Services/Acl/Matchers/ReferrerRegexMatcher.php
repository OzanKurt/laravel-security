<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;

class ReferrerRegexMatcher implements AclMatcher
{
    public function matches(Request $request, string $value): bool
    {
        $ref = $request->header('Referer') ?? '';
        return (bool) @preg_match($value, $ref);
    }
}

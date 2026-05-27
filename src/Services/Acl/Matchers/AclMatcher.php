<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;

interface AclMatcher
{
    public function matches(Request $request, string $value): bool;
}

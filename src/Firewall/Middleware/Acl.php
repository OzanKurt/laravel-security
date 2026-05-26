<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Acl\AclEvaluator;

class Acl
{
    public function __construct(private AclEvaluator $evaluator) {}

    public function handle(Request $request, Closure $next)
    {
        // Short-circuit if the request was already bypassed (Milestone H)
        if ($request->attributes->get('shield.bypassed')) {
            return $next($request);
        }

        $decision = $this->evaluator->evaluate($request);

        if ($decision->action === 'allow') {
            $request->attributes->set('shield.acl_allowed', true);
            return $next($request);
        }

        if ($decision->isDeny()) {
            return $this->respondDeny($request, $decision);
        }

        return $next($request);
    }

    private function respondDeny(Request $request, $decision)
    {
        $response = config('shield.responses.block');

        if ($request->expectsJson()) {
            return response()->json([
                'reason' => 'acl_' . $decision->action,
                'message' => trans('shield::responses.access_denied.message'),
            ], $response['code'] ?? 403);
        }

        abort($response['code'] ?? 403, trans('shield::responses.access_denied.message'));
    }
}

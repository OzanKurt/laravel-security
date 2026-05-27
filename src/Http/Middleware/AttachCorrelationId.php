<?php

namespace OzanKurt\Shield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Support\CorrelationId;

class AttachCorrelationId
{
    public function __construct(private CorrelationId $correlation) {}

    public function handle(Request $request, Closure $next)
    {
        $incoming = $request->header('X-Correlation-Id');

        if ($incoming && preg_match('/^[0-9a-f-]{36}$/', $incoming)) {
            $this->correlation->set($incoming);
        }

        $response = $next($request);

        return $response->header('X-Correlation-Id', $this->correlation->get());
    }
}

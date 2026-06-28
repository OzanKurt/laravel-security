<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OzanKurt\Shield\Services\Honeypot\HoneypotTrap;

class HoneypotController extends Controller
{
    public function trap(Request $request, HoneypotTrap $trap)
    {
        $trap->handle($request, $request->path());
    }
}

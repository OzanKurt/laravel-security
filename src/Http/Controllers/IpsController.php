<?php

namespace OzanKurt\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Security\Http\Middleware\SecurityDashboardMiddleware;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use OzanKurt\Security\Security;

class IpsController extends Controller
{
    public function index()
    {
        return view('security::dashboard.ips.index')->with([

        ]);
    }
}

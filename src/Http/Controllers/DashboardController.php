<?php

namespace OzanKurt\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Security\Http\Middleware\SecurityDashboardMiddleware;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use OzanKurt\Security\Notifications\Notifiable;
use OzanKurt\Security\Notifications\SecurityReportNotification;
use OzanKurt\Security\Security;

class DashboardController extends Controller
{
    public function index()
    {
        $attacksDetected = Log::count();
        $ipsBlocked = Ip::blocked()->count();
        $requestsBlocked = Ip::blocked()->sum('request_count');

        $recentlyModifiedFiles = app('security')->getRecentlyModifiedFiles(now()->subDays(7), 100);

        return view('security::dashboard.index')->with([
            'attacksDetected' => $attacksDetected,
            'ipsBlocked' => $ipsBlocked,
            'requestsBlocked' => $requestsBlocked,

            'recentlyModifiedFiles' => $recentlyModifiedFiles,
        ]);
    }

    public function whitelist()
    {
        return view('security::dashboard.whitelist');
    }

    public function whitelistStore()
    {
        return redirect()->route('security.whitelist');
    }

    public function whitelistDestroy()
    {
        return redirect()->route('security.whitelist');
    }

    public function blacklist()
    {
        return view('security::dashboard.blacklist');
    }

    public function blacklistStore()
    {
        return redirect()->route('security.blacklist');
    }

    public function blacklistDestroy()
    {
        return redirect()->route('security.blacklist');
    }
}
<?php

namespace OzanKurt\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Security\Enums\IpEntryType;
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
        $ipsBlocked = Ip::whereIn('entry_type', [IpEntryType::BLOCK])->count();
        $requestsBlocked = Ip::whereIn('entry_type', [IpEntryType::BLOCK, IpEntryType::BLACKLIST])->sum('request_count');

        $recentlyModifiedFiles = app('security')->getRecentlyModifiedFiles(now()->subDays(7), 100);

        return view('security::dashboard.index')->with([
            'attacksDetected' => $attacksDetected,
            'ipsBlocked' => $ipsBlocked,
            'requestsBlocked' => $requestsBlocked,

            'recentlyModifiedFiles' => $recentlyModifiedFiles,
        ]);
    }
}

<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Shield\Enums\IpEntryType;
use OzanKurt\Shield\Http\Middleware\ShieldDashboardMiddleware;
use OzanKurt\Shield\Models\Ip;
use OzanKurt\Shield\Models\Log;
use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Notifications\SecurityReportNotification;
use OzanKurt\Shield\Shield;

class DashboardController extends Controller
{
    public function index()
    {
        $attacksDetected = Log::count();
        $ipsBlocked = Ip::whereIn('entry_type', [IpEntryType::BLOCK])->count();
        $requestsBlocked = Ip::whereIn('entry_type', [IpEntryType::BLOCK, IpEntryType::BLACKLIST])->sum('request_count');

        $recentlyModifiedFiles = app('shield')->getRecentlyModifiedFiles(now()->subDays(7), 100);

        return view('shield::dashboard.index')->with([
            'attacksDetected' => $attacksDetected,
            'ipsBlocked' => $ipsBlocked,
            'requestsBlocked' => $requestsBlocked,

            'recentlyModifiedFiles' => $recentlyModifiedFiles,
        ]);
    }
}

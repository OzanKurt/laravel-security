<?php

namespace OzanKurt\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Security\DataTables\IpsDataTable;
use OzanKurt\Security\Http\Middleware\SecurityDashboardMiddleware;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use OzanKurt\Security\Security;

class IpsController extends Controller
{
    public function index()
    {
        $dataTable = app(IpsDataTable::class);

        if (request('mode') == 'dataTable' || request()->ajax()) {
            return $dataTable->ajax();
        }

        $ipsCount = Ip::count();

        return view('security::dashboard.ips.index')->with([
            'ipsCount' => $ipsCount,
            'dataTable' => $dataTable->html(),
        ]);
    }
}

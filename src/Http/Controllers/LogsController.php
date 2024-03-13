<?php

namespace OzanKurt\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Security\DataTables\LogsDataTable;
use OzanKurt\Security\Http\Middleware\SecurityDashboardMiddleware;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use OzanKurt\Security\Security;

class LogsController extends Controller
{
    public function index()
    {
        $dataTable = app(LogsDataTable::class);

        if (request('mode') == 'dataTable' || request()->ajax()) {
            return $dataTable->ajax();
        }

        $logsCount = Log::count();

        return view('security::dashboard.logs.index')->with([
            'logsCount' => $logsCount,
            'dataTable' => $dataTable->html(),
        ]);
    }
}

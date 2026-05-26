<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Shield\DataTables\LogsDataTable;
use OzanKurt\Shield\Http\Middleware\SecurityDashboardMiddleware;
use OzanKurt\Shield\Models\Ip;
use OzanKurt\Shield\Models\Log;
use OzanKurt\Shield\Security;

class LogsController extends Controller
{
    public function index()
    {
        $dataTable = app(LogsDataTable::class);

        if (request('mode') === 'dataTable' || request()->ajax()) {
            return $dataTable->ajax();
        }

        $logsCount = Log::count();

        return view('security::dashboard.logs.index')->with([
            'logsCount' => $logsCount,
            'dataTable' => $dataTable->html(),
        ]);
    }
}

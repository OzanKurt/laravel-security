<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Shield\DataTables\LogsDataTable;
use OzanKurt\Shield\Http\Middleware\ShieldDashboardMiddleware;
use OzanKurt\Shield\Models\Ip;
use OzanKurt\Shield\Models\Log;
use OzanKurt\Shield\Shield;

class LogsController extends Controller
{
    public function index()
    {
        $dataTable = app(LogsDataTable::class);

        if (request('mode') === 'dataTable' || request()->ajax()) {
            return $dataTable->ajax();
        }

        $logsCount = Log::count();

        return view('shield::dashboard.logs.index')->with([
            'logsCount' => $logsCount,
            'dataTable' => $dataTable->html(),
        ]);
    }
}

<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Shield\DataTables\AuthLogsDataTable;
use OzanKurt\Shield\Models\AuthLog;

class AuthLogsController extends Controller
{
    public function index()
    {
        $dataTable = app(AuthLogsDataTable::class);

        if (request('mode') === 'dataTable' || request()->ajax()) {
            return $dataTable->ajax();
        }

        $authLogsCount = AuthLog::count();

        return view('security::dashboard.auth-logs.index')->with([
            'authLogsCount' => $authLogsCount,
            'dataTable' => $dataTable->html(),
        ]);
    }
}

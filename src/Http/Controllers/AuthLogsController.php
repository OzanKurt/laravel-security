<?php

namespace OzanKurt\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Security\DataTables\AuthLogsDataTable;
use OzanKurt\Security\Models\AuthLog;

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

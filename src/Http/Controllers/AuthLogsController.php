<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use OzanKurt\Shield\Models\AuthLog;

class AuthLogsController extends Controller
{
    private array $columnMap = [
        0 => 'id',
        1 => 'email',
        2 => 'is_successful',
        3 => 'ip',
        4 => 'user_agent',
        5 => 'referrer',
        6 => 'request_data',
        7 => 'meta_data',
        8 => 'created_at',
        9 => 'updated_at',
    ];

    public function index(Request $request)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->ajaxData($request);
        }

        $authLogsCount = AuthLog::count();

        return view('shield::dashboard.auth-logs.index', compact('authLogsCount'));
    }

    // -------------------------------------------------------------------------
    // DataTables server-side JSON endpoint
    // -------------------------------------------------------------------------

    private function ajaxData(Request $request)
    {
        $model = config('shield.database.auth_log.model', AuthLog::class);
        $tableName = config('shield.database.table_prefix') . config('shield.database.auth_log.table');
        $query = $model::query()->select($tableName . '.*')->with('user');

        $total = $model::count();

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('ip', 'like', "%{$search}%")
                  ->orWhere('user_agent', 'like', "%{$search}%");
            });
        }

        $filtered = $query->count();

        $orderColIndex = (int) $request->input('order.0.column', 0);
        $orderDir = in_array(strtolower($request->input('order.0.dir', 'desc')), ['asc', 'desc'])
            ? strtolower($request->input('order.0.dir', 'desc'))
            : 'desc';
        $orderColumn = $this->columnMap[$orderColIndex] ?? 'id';
        $query->orderBy($orderColumn, $orderDir);

        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 25)));

        $rows = $query->offset($start)->limit($length)->get();

        return response()->json([
            'draw'            => (int) $request->input('draw'),
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $rows->map(fn ($log) => [
                'id'           => $log->id,
                'email'        => e($log->email ?? ''),
                'is_successful' => $log->is_successful
                    ? __('shield::dashboard.yes')
                    : __('shield::dashboard.no'),
                'ip'           => e($log->ip ?? ''),
                'user_agent'   => e($log->user_agent ?? ''),
                'referrer'     => e($log->referrer ?? ''),
                'request_data' => app('shield')->highlightJson($log->request_data ?? []),
                'meta_data'    => app('shield')->highlightJson($log->meta_data ?? []),
                'created_at'   => $log->created_at?->format('Y-m-d H:i:s') ?? '',
                'updated_at'   => $log->updated_at?->format('Y-m-d H:i:s') ?? '',
            ]),
        ]);
    }
}

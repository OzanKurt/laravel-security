<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Log;

class LogsController extends Controller
{
    private array $columnMap = [
        0  => 'id',
        1  => 'user_id',
        2  => 'middleware',
        3  => 'level',
        4  => 'ip',
        5  => 'url',
        6  => 'user_agent',
        7  => 'referrer',
        8  => 'request_data',
        9  => 'meta_data',
        10 => 'created_at',
        11 => 'updated_at',
    ];

    public function index(Request $request)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->ajaxData($request);
        }

        $logsCount = Log::count();

        return view('shield::dashboard.logs.index', compact('logsCount'));
    }

    // -------------------------------------------------------------------------
    // DataTables server-side JSON endpoint
    // -------------------------------------------------------------------------

    private function ajaxData(Request $request)
    {
        $model = config('shield.database.log.model', Log::class);
        $tableName = config('shield.database.table_prefix') . config('shield.database.log.table');
        $query = $model::query()->select($tableName . '.*')->with('user');

        $total = $model::count();

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('ip', 'like', "%{$search}%")
                  ->orWhere('url', 'like', "%{$search}%")
                  ->orWhere('middleware', 'like', "%{$search}%")
                  ->orWhere('level', 'like', "%{$search}%")
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

        $baseUrl = url('/');
        $nameField = config('shield.dashboard.user_name_field', 'name');

        return response()->json([
            'draw'            => (int) $request->input('draw'),
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $rows->map(fn ($log) => [
                'id'           => $log->id,
                'user_name'    => $log->user?->{$nameField} ?? 'Guest',
                'middleware'   => e($log->middleware ?? ''),
                'level'        => e($log->level ?? ''),
                'ip'           => e($log->ip ?? ''),
                'url'          => e(str_replace($baseUrl, '', $log->url ?? '')),
                'user_agent'   => e($log->user_agent ?? ''),
                'referrer'     => e($log->referrer ?? ''),
                'request_data' => app('shield')->highlightJson($log->request_data),
                'meta_data'    => app('shield')->highlightJson($log->meta_data),
                'created_at'   => $log->created_at?->format('Y-m-d H:i:s') ?? '',
                'updated_at'   => $log->updated_at?->format('Y-m-d H:i:s') ?? '',
            ]),
        ]);
    }
}

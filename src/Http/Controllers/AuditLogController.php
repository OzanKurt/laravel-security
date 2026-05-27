<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OzanKurt\Shield\Models\AuditLog;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Models\Lookups\LogLevel;

class AuditLogController extends Controller
{
    private array $columnMap = [
        0 => 'id',
        1 => 'kind_id',
        2 => 'severity_id',
        3 => 'actor_type',
        4 => 'description',
        5 => 'subject_type',
        6 => 'ip',
        7 => 'created_at',
    ];

    public function index(Request $request)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->ajaxData($request);
        }

        $auditLogCount = AuditLog::count();
        $kinds = AuditLogKind::orderBy('label')->get();
        $severities = LogLevel::orderBy('sort_order')->get();

        return view('shield::dashboard.audit-log.index', compact('auditLogCount', 'kinds', 'severities'));
    }

    // -------------------------------------------------------------------------
    // DataTables server-side JSON endpoint
    // -------------------------------------------------------------------------

    private function ajaxData(Request $request)
    {
        $query = AuditLog::query()->with(['kind', 'severity']);

        // --------------- filters ---------------

        if ($kindId = $request->input('filter_kind_id')) {
            $query->where('kind_id', (int) $kindId);
        }

        if ($severityId = $request->input('filter_severity_id')) {
            $query->where('severity_id', (int) $severityId);
        }

        if ($actorType = $request->input('filter_actor_type')) {
            $query->where('actor_type', $actorType);
        }

        if ($correlationId = $request->input('filter_correlation_id')) {
            $query->where('correlation_id', $correlationId);
        }

        if ($dateFrom = $request->input('filter_date_from')) {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo = $request->input('filter_date_to')) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $total = AuditLog::count();
        $filtered = $query->count();

        // --------------- search ---------------

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('actor_type', 'like', "%{$search}%")
                  ->orWhere('subject_type', 'like', "%{$search}%")
                  ->orWhere('ip', 'like', "%{$search}%")
                  ->orWhere('correlation_id', 'like', "%{$search}%");
            });
            $filtered = $query->count();
        }

        // --------------- ordering ---------------

        $orderColIndex = (int) $request->input('order.0.column', 0);
        $orderDir = in_array(strtolower($request->input('order.0.dir', 'desc')), ['asc', 'desc'])
            ? strtolower($request->input('order.0.dir', 'desc'))
            : 'desc';
        $orderColumn = $this->columnMap[$orderColIndex] ?? 'id';
        $query->orderBy($orderColumn, $orderDir);

        // --------------- pagination ---------------

        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 25)));

        $rows = $query->offset($start)->limit($length)->get();

        return response()->json([
            'draw'            => (int) $request->input('draw'),
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $rows->map(fn ($row) => [
                'id'             => $row->id,
                'kind'           => $row->kind?->label ?? $row->kind_id,
                'severity'       => $row->severity?->label ?? $row->severity_id,
                'actor_type'     => $row->actor_type ?? '',
                'description'    => e($row->description),
                'subject_type'   => $row->subject_type ?? '',
                'subject_id'     => $row->subject_id ?? '',
                'ip'             => $row->ip ?? '',
                'correlation_id' => $row->correlation_id ?? '',
                'created_at'     => $row->created_at?->format('Y-m-d H:i:s') ?? '',
            ]),
        ]);
    }
}

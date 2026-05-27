<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Acl;

class AclController extends Controller
{
    private array $columnMap = [
        0 => 'id',
        1 => 'value',
        2 => 'kind_id',
        3 => 'action_id',
        4 => 'hit_count',
        5 => 'created_at',
        6 => 'updated_at',
    ];

    public function index(Request $request)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->ajaxData($request);
        }

        $aclCount = Acl::count();

        return view('shield::dashboard.acl.index', compact('aclCount'));
    }

    public function postAction(Acl $acl)
    {
        $action = request('action');

        if ($action === 'allow') {
            app(\OzanKurt\Shield\Services\Lookups\LookupResolver::class)->flush(\OzanKurt\Shield\Models\Lookups\AclAction::class);
            $actionId = app(\OzanKurt\Shield\Services\Lookups\LookupResolver::class)->id(
                \OzanKurt\Shield\Models\Lookups\AclAction::class, 'allow'
            );
            $acl->action_id = $actionId;
            $acl->save();

            return response()->json([
                'actions' => [
                    [
                        'type' => 'toastr',
                        'data' => ['type' => 'success', 'title' => 'Allowed', 'message' => 'Entry set to allow.'],
                    ],
                    ['type' => 'reloadDataTable', 'data' => ['dataTableId' => 'aclDataTable']],
                ],
            ]);
        }

        if ($action === 'blacklist') {
            $actionId = app(\OzanKurt\Shield\Services\Lookups\LookupResolver::class)->id(
                \OzanKurt\Shield\Models\Lookups\AclAction::class, 'blacklist'
            );
            $acl->action_id = $actionId;
            $acl->save();

            return response()->json([
                'actions' => [
                    [
                        'type' => 'toastr',
                        'data' => ['type' => 'success', 'title' => 'Blacklisted', 'message' => 'Entry blacklisted.'],
                    ],
                    ['type' => 'reloadDataTable', 'data' => ['dataTableId' => 'aclDataTable']],
                ],
            ]);
        }

        if ($action === 'delete') {
            $acl->delete();

            return response()->json([
                'actions' => [
                    [
                        'type' => 'toastr',
                        'data' => ['type' => 'success', 'title' => 'Deleted', 'message' => 'Entry deleted.'],
                    ],
                    ['type' => 'reloadDataTable', 'data' => ['dataTableId' => 'aclDataTable']],
                ],
            ]);
        }

        throw new \Exception('Invalid action');
    }

    // -------------------------------------------------------------------------
    // DataTables server-side JSON endpoint
    // -------------------------------------------------------------------------

    private function ajaxData(Request $request)
    {
        $query = Acl::query()->with(['kind', 'action']);

        $total = Acl::count();

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('value', 'like', "%{$search}%")
                  ->orWhere('reason', 'like', "%{$search}%")
                  ->orWhere('source', 'like', "%{$search}%");
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
            'data'            => $rows->map(fn ($row) => [
                'id'        => $row->id,
                'value'     => e($row->value),
                'kind'      => $row->kind?->label ?? '',
                'action'    => $row->action?->label ?? '',
                'source'    => e($row->source ?? ''),
                'reason'    => e($row->reason ?? ''),
                'hit_count' => $row->hit_count ?? 0,
                'expires_at' => $row->expires_at?->format('Y-m-d H:i:s') ?? '',
                'created_at' => $row->created_at?->format('Y-m-d H:i:s') ?? '',
                'updated_at' => $row->updated_at?->format('Y-m-d H:i:s') ?? '',
                'actions'   => $this->renderActionsHtml($row),
            ]),
        ]);
    }

    private function renderActionsHtml(Acl $acl): string
    {
        $links = [];

        $allowRoute = app('shield')->route('acl.action', ['acl' => $acl->id, 'action' => 'allow']);
        $links[] = <<<HTML
            <a href="{$allowRoute}" class="btn btn-sm btn-success ajax-link" title="Allow"
                data-bs-toggle="tooltip" data-bs-title="Allow">
                <i class="far fa-fw fa-check"></i>
            </a>
        HTML;

        $blacklistRoute = app('shield')->route('acl.action', ['acl' => $acl->id, 'action' => 'blacklist']);
        $links[] = <<<HTML
            <a href="{$blacklistRoute}" class="btn btn-sm btn-warning ajax-link" title="Blacklist"
                data-bs-toggle="tooltip" data-bs-title="Blacklist">
                <i class="far fa-fw fa-times"></i>
            </a>
        HTML;

        $deleteRoute = app('shield')->route('acl.action', ['acl' => $acl->id, 'action' => 'delete']);
        $links[] = <<<HTML
            <a href="{$deleteRoute}" class="btn btn-sm btn-danger ajax-link" title="Delete"
                data-bs-toggle="tooltip" data-bs-title="Delete">
                <i class="far fa-fw fa-trash"></i>
            </a>
        HTML;

        return implode(' ', $links);
    }
}

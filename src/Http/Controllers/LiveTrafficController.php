<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Lookups\ActionKind;
use OzanKurt\Shield\Models\LiveTrafficRecord;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class LiveTrafficController extends Controller
{
    public function index(Request $request, LookupResolver $lookups)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->ajax($request, $lookups);
        }
        return view('shield::dashboard.live-traffic.index');
    }

    private function ajax(Request $request, LookupResolver $lookups)
    {
        $query = LiveTrafficRecord::query();

        $total = $query->count();

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('ip', 'like', "%{$search}%")
                  ->orWhere('url', 'like', "%{$search}%")
                  ->orWhere('user_agent', 'like', "%{$search}%");
            });
        }

        if ($actionName = $request->input('filter_action')) {
            $actionId = $lookups->id(ActionKind::class, $actionName);
            if ($actionId !== null) {
                $query->where('action_taken_id', $actionId);
            }
        }

        $filtered = $query->count();
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $rows = $query->latest('id')->offset($start)->limit($length)->get()->map(fn ($r) => [
            'id' => $r->id,
            'ip' => $r->ip,
            'country_code' => $r->country_code,
            'method' => $r->method,
            'url' => $r->url,
            'status_code' => $r->status_code,
            'response_time_ms' => $r->response_time_ms,
            'action' => $lookups->name(ActionKind::class, $r->action_taken_id),
            'created_at' => (string) $r->created_at,
        ]);

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }
}

<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Lookups\ScannerBackend;
use OzanKurt\Shield\Models\ScannerFinding;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Scanner\Backends\ComposerAuditBackend;
use OzanKurt\Shield\Services\Scanner\Scanner;

class ComposerAuditController extends Controller
{
    public function index(Request $request, LookupResolver $lookups)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->ajax($request, $lookups);
        }

        return view('shield::dashboard.composer-audit.index', [
            'composer_backend_id' => $lookups->id(ScannerBackend::class, 'composer_audit'),
        ]);
    }

    public function refresh(Scanner $scanner)
    {
        $run = $scanner->run([], ['composer_audit'], 'manual');

        return response()->json([
            'actions' => [
                ['type' => 'toastr', 'data' => ['type' => 'success', 'message' => "Composer audit completed (run #{$run->id}, {$run->findings_count} findings)"]],
                ['type' => 'reloadDataTable', 'data' => ['dataTableId' => 'composerAuditTable']],
            ],
        ]);
    }

    private function ajax(Request $request, LookupResolver $lookups)
    {
        $composerBackendId = $lookups->id(ScannerBackend::class, 'composer_audit');

        $query = ScannerFinding::query()->where('backend_id', $composerBackendId);
        $total = $query->count();

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('signature_ref', 'like', "%{$search}%")
                  ->orWhere('matched_content', 'like', "%{$search}%");
            });
        }

        $filtered = $query->count();
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $rows = $query->latest('id')->offset($start)->limit($length)->get()->map(function ($r) use ($lookups) {
            $meta = is_array($r->matched_content) ? $r->matched_content : ['summary' => (string) $r->matched_content];
            return [
                'id' => $r->id,
                'advisory' => $r->signature_ref,
                'severity' => $lookups->name(\OzanKurt\Shield\Models\Lookups\LogLevel::class, $r->severity_id),
                'summary' => $meta['summary'] ?? null,
                'created_at' => (string) $r->created_at,
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }
}

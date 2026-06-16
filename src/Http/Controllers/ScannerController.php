<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Lookups\ScannerFindingStatus;
use OzanKurt\Shield\Models\Lookups\ScannerStatus;
use OzanKurt\Shield\Models\ScannerFinding;
use OzanKurt\Shield\Models\ScannerRun;
use OzanKurt\Shield\Models\Signature;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Scanner\Scanner;

class ScannerController extends Controller
{
    public function index(LookupResolver $lookups)
    {
        $latestRun = ScannerRun::query()->latest('id')->first();
        $latestRunStatus = $latestRun
            ? $lookups->name(ScannerStatus::class, $latestRun->status_id)
            : null;

        $quarantinedId = $lookups->id(ScannerFindingStatus::class, 'quarantined');
        $openId = $lookups->id(ScannerFindingStatus::class, 'open');

        $stats = [
            'latest_run' => $latestRun,
            'latest_run_status' => $latestRunStatus,
            'total_runs' => ScannerRun::count(),
            'open_findings' => ScannerFinding::where('status_id', $openId)->count(),
            'quarantined' => ScannerFinding::where('status_id', $quarantinedId)->count(),
            'total_signatures' => Signature::count(),
        ];

        return view('shield::dashboard.scanner.index', compact('stats'));
    }

    public function runs(Request $request, LookupResolver $lookups)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->runsJson($request, $lookups);
        }
        return view('shield::dashboard.scanner.runs');
    }

    public function findings(Request $request, LookupResolver $lookups)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->findingsJson($request, $lookups);
        }
        return view('shield::dashboard.scanner.findings');
    }

    public function signatures(Request $request, LookupResolver $lookups)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->signaturesJson($request, $lookups);
        }
        return view('shield::dashboard.scanner.signatures');
    }

    public function startScan(Request $request, Scanner $scanner)
    {
        $targets = (array) $request->input('targets', ['app_files', 'public_uploads']);
        $backends = (array) $request->input('backends', []);

        $run = $scanner->run($targets, $backends, 'manual');

        return response()->json([
            'actions' => [
                ['type' => 'toastr', 'data' => ['type' => 'success', 'message' => "Scan #{$run->id} completed."]],
            ],
            'run' => [
                'id' => $run->id,
                'uuid' => $run->uuid,
                'findings_count' => $run->findings_count,
                'findings_critical_count' => $run->findings_critical_count,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // JSON endpoints
    // -------------------------------------------------------------------------

    private function runsJson(Request $request, LookupResolver $lookups)
    {
        $query = ScannerRun::query();
        $total = $query->count();
        $filtered = $total;

        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $rows = $query->latest('id')->offset($start)->limit($length)->get()->map(fn ($r) => [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'status' => $lookups->name(ScannerStatus::class, $r->status_id),
            'files_scanned' => $r->files_scanned,
            'findings_count' => $r->findings_count,
            'findings_critical_count' => $r->findings_critical_count,
            'started_at' => (string) $r->started_at,
            'finished_at' => (string) $r->finished_at,
        ]);

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }

    private function findingsJson(Request $request, LookupResolver $lookups)
    {
        $query = ScannerFinding::query();
        $total = $query->count();

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('file_path', 'like', "%{$search}%")
                  ->orWhere('signature_ref', 'like', "%{$search}%");
            });
        }

        $filtered = $query->count();
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $rows = $query->latest('id')->offset($start)->limit($length)->get()->map(fn ($r) => [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'run' => $r->scanner_run_id,
            'file_path' => $r->file_path,
            'signature_ref' => $r->signature_ref,
            'severity' => $lookups->name(\OzanKurt\Shield\Models\Lookups\LogLevel::class, $r->severity_id),
            'status' => $lookups->name(ScannerFindingStatus::class, $r->status_id),
            'created_at' => (string) $r->created_at,
        ]);

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }

    private function signaturesJson(Request $request, LookupResolver $lookups)
    {
        $query = Signature::query();
        $total = $query->count();

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('source_ref', 'like', "%{$search}%");
            });
        }

        $filtered = $query->count();
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $rows = $query->orderBy('id')->offset($start)->limit($length)->get()->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'source' => $r->source,
            'source_ref' => $r->source_ref,
            'kind' => $r->kind,
            'severity' => $lookups->name(\OzanKurt\Shield\Models\Lookups\LogLevel::class, $r->severity_id),
            'enabled' => $r->is_enabled ? 'Yes' : 'No',
            'version' => $r->version,
        ]);

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }
}

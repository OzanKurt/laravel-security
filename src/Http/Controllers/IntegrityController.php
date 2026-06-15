<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OzanKurt\Shield\Models\IntegrityBaseline;
use OzanKurt\Shield\Models\IntegrityChange;
use OzanKurt\Shield\Models\IntegrityRun;
use OzanKurt\Shield\Models\Lookups\IntegrityChangeType;
use OzanKurt\Shield\Models\Lookups\IntegrityComparisonBasis;
use OzanKurt\Shield\Models\Lookups\IntegrityStatus;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Services\Integrity\IntegrityScanner;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class IntegrityController extends Controller
{
    public function index(LookupResolver $lookups)
    {
        $latestRun = IntegrityRun::query()->latest('id')->first();
        $baseline = IntegrityBaseline::query()->latest('id')->first();

        $stats = [
            'latest_run' => $latestRun,
            'latest_run_status' => $latestRun ? $lookups->name(IntegrityStatus::class, $latestRun->status_id) : null,
            'total_runs' => IntegrityRun::count(),
            'baseline' => $baseline,
            'drift' => $latestRun?->count_vs_known_good ?? 0,
            'disks' => array_keys((array) config('shield.integrity.disks', [])),
        ];

        return view('shield::dashboard.integrity.index', compact('stats'));
    }

    public function runs(Request $request, LookupResolver $lookups)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->runsJson($request, $lookups);
        }

        return view('shield::dashboard.integrity.runs');
    }

    public function changes(Request $request, LookupResolver $lookups)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->changesJson($request, $lookups);
        }

        return view('shield::dashboard.integrity.changes');
    }

    public function scan(Request $request, IntegrityScanner $scanner)
    {
        $disk = (string) $request->input('disk', 'app');
        $run = $scanner->run($disk, 'manual');
        $status = $run->status->name ?? 'unknown';

        return response()->json([
            'actions' => [
                ['type' => 'toastr', 'data' => [
                    'type' => $status === 'tamper_suspected' ? 'error' : 'success',
                    'message' => "Integrity scan #{$run->id} [{$disk}]: {$status}.",
                ]],
                ['type' => 'reloadDataTable', 'data' => ['dataTableId' => 'integrityRunsTable']],
            ],
        ]);
    }

    public function bless(Request $request, IntegrityScanner $scanner)
    {
        $disk = (string) $request->input('disk', 'app');

        try {
            $baseline = $scanner->bless($disk);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'actions' => [
                    ['type' => 'toastr', 'data' => ['type' => 'error', 'message' => $e->getMessage()]],
                ],
            ]);
        }

        return response()->json([
            'actions' => [
                ['type' => 'toastr', 'data' => [
                    'type' => 'success',
                    'message' => "Baseline approved for [{$disk}]: {$baseline->files_total} files.",
                ]],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // JSON endpoints
    // -------------------------------------------------------------------------

    private function runsJson(Request $request, LookupResolver $lookups)
    {
        $query = IntegrityRun::query();
        $total = $query->count();

        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $rows = $query->latest('id')->offset($start)->limit($length)->get()->map(fn ($r) => [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'disk' => $r->disk,
            'status' => $lookups->name(IntegrityStatus::class, $r->status_id),
            'severity' => $r->severity_id ? $lookups->name(LogLevel::class, $r->severity_id) : '-',
            'new' => $r->count_new,
            'modified' => $r->count_modified,
            'deleted' => $r->count_deleted,
            'vs_known_good' => $r->count_vs_known_good,
            'files_total' => $r->files_total,
            'started_at' => (string) $r->started_at,
        ]);

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $rows,
        ]);
    }

    private function changesJson(Request $request, LookupResolver $lookups)
    {
        $query = IntegrityChange::query();

        if ($runId = $request->input('run_id')) {
            $query->where('integrity_run_id', (int) $runId);
        }

        $total = $query->count();

        if ($search = $request->input('search.value')) {
            $query->where('path', 'like', "%{$search}%");
        }

        $filtered = $query->count();
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $rows = $query->orderByDesc('severity_id')->orderBy('id')->offset($start)->limit($length)->get()->map(fn ($r) => [
            'id' => $r->id,
            'run' => $r->integrity_run_id,
            'path' => $r->path,
            'change_type' => $lookups->name(IntegrityChangeType::class, $r->change_type_id),
            'compared_to' => $lookups->name(IntegrityComparisonBasis::class, $r->compared_to_id),
            'severity' => $r->severity_id ? $lookups->name(LogLevel::class, $r->severity_id) : '-',
        ]);

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }
}

@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Scanner</h2>

        <div class="row mt-4 g-3">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Latest run</div>
                        <div class="fs-4">{{ $stats['latest_run'] ? '#'.$stats['latest_run']->id : '—' }}</div>
                        <div class="small text-muted">{{ $stats['latest_run_status'] ?? '' }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Total runs</div>
                        <div class="fs-4">{{ $stats['total_runs'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Open findings</div>
                        <div class="fs-4">{{ $stats['open_findings'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Quarantined</div>
                        <div class="fs-4">{{ $stats['quarantined'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Signatures</div>
                        <div class="fs-4">{{ $stats['total_signatures'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-body">
                        <h5>Start a scan</h5>
                        <form id="startScanForm">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label small">Targets (comma-separated)</label>
                                    <input type="text" name="targets" class="form-control form-control-sm" value="app_files,public_uploads,recently_modified">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small">Backends (empty = all available)</label>
                                    <input type="text" name="backends" class="form-control form-control-sm" placeholder="native,clamav,composer_audit">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">Run scan</button>
                                </div>
                            </div>
                        </form>
                        <pre id="scanResult" class="mt-3 small" style="display:none;"></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <ul class="nav nav-pills">
                    <li class="nav-item"><a class="nav-link" href="{{ app('shield')->route('scanner.runs') }}">Runs</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ app('shield')->route('scanner.findings') }}">Findings</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ app('shield')->route('scanner.signatures') }}">Signatures</a></li>
                </ul>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#startScanForm').on('submit', function (e) {
        e.preventDefault();
        const targets = $(this).find('[name=targets]').val().split(',').map(s => s.trim()).filter(Boolean);
        const backends = $(this).find('[name=backends]').val().split(',').map(s => s.trim()).filter(Boolean);

        $('#scanResult').show().text('Running...');
        $.post('{{ app('shield')->route('scanner.run') }}', { targets, backends })
            .done(function (res) {
                $('#scanResult').text(JSON.stringify(res, null, 2));
            })
            .fail(function (xhr) {
                $('#scanResult').text('Error: ' + (xhr.responseText || xhr.statusText));
            });
    });
});
</script>
@endpush

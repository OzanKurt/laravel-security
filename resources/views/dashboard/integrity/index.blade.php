@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>File integrity</h2>

        <div class="row mt-4 g-3">
            <div class="col-md-3">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Latest run</div>
                        <div class="fs-4">{{ $stats['latest_run'] ? '#'.$stats['latest_run']->id : '—' }}</div>
                        <div class="small text-muted">{{ $stats['latest_run_status'] ?? '' }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Total runs</div>
                        <div class="fs-4">{{ $stats['total_runs'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Baseline</div>
                        <div class="fs-4">{{ $stats['baseline'] ? ($stats['baseline']->signed ? 'Approved' : 'Provisional') : '—' }}</div>
                        <div class="small text-muted">{{ $stats['baseline'] ? $stats['baseline']->files_total.' files' : 'not established' }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="text-muted small">Differs from baseline</div>
                        <div class="fs-4">{{ $stats['drift'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        @if ($stats['baseline'] && ! $stats['baseline']->signed)
            <div class="alert alert-warning mt-4">
                A provisional baseline was established from the current disk state and is <strong>not yet trusted</strong>.
                Review the files, then click <em>Approve baseline</em>.
            </div>
        @endif

        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-body">
                        <h5>Run / approve</h5>
                        <form id="integrityForm" class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small">Disk</label>
                                <input type="text" name="disk" class="form-control form-control-sm" value="app">
                            </div>
                            <div class="col-md-6 d-flex align-items-end gap-2">
                                <button type="button" id="runBtn" class="btn btn-primary btn-sm">Run scan</button>
                                <button type="button" id="blessBtn" class="btn btn-outline-secondary btn-sm">Approve baseline</button>
                            </div>
                        </form>
                        <pre id="integrityResult" class="mt-3 small" style="display:none;"></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <ul class="nav nav-pills">
                    <li class="nav-item"><a class="nav-link" href="{{ app('shield')->route('integrity.runs') }}">Runs</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ app('shield')->route('integrity.changes') }}">Changes</a></li>
                </ul>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    function post(url) {
        const disk = $('[name=disk]').val();
        $('#integrityResult').show().text('Working...');
        $.post(url, { disk })
            .done(function (res) {
                const msgs = (res.actions || []).filter(a => a.type === 'toastr').map(a => a.data.message);
                $('#integrityResult').text(msgs.join('\n') || JSON.stringify(res, null, 2));
            })
            .fail(function (xhr) {
                $('#integrityResult').text('Error: ' + (xhr.responseText || xhr.statusText));
            });
    }
    $('#runBtn').on('click', () => post('{{ app('shield')->route('integrity.scan') }}'));
    $('#blessBtn').on('click', () => post('{{ app('shield')->route('integrity.bless') }}'));
});
</script>
@endpush

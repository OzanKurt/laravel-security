@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <div class="d-flex align-items-center justify-content-between">
            <h2 class="mb-0">Composer audit</h2>
            <button id="refresh" class="btn btn-primary btn-sm">Run audit now</button>
        </div>
        <p class="text-muted small mt-2">
            Surfaces CVEs in installed composer packages via <code>composer audit</code>.
        </p>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table id="composerAuditTable" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Advisory</th>
                            <th>Severity</th>
                            <th>Summary</th>
                            <th>Detected</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    window.DataTables = window.DataTables || {};
    window.DataTables.composerAuditTable = $('#composerAuditTable').DataTable({
        processing: true, serverSide: true, responsive: true,
        order: [[0, 'desc']],
        ajax: { url: '{{ app('shield')->route('composer-audit.index') }}', data: { mode: 'dataTable' } },
        columns: [
            { data: 'id' }, { data: 'advisory' }, { data: 'severity' },
            { data: 'summary' }, { data: 'created_at' },
        ],
    });

    $('#refresh').on('click', function () {
        $(this).prop('disabled', true).text('Running...');
        $.post('{{ app('shield')->route('composer-audit.refresh') }}')
            .done(window.ajax_complete_handler)
            .always(() => $('#refresh').prop('disabled', false).text('Run audit now'));
    });
});
</script>
@endpush

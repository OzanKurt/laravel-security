@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Integrity changes</h2>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table id="integrityChangesTable" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Run</th>
                            <th>Path</th>
                            <th>Change</th>
                            <th>Compared to</th>
                            <th>Severity</th>
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
    const params = new URLSearchParams(window.location.search);
    $('#integrityChangesTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        ajax: {
            url: '{{ app('shield')->route('integrity.changes') }}',
            data: { mode: 'dataTable', run_id: params.get('run_id') || '' },
        },
        columns: [
            { data: 'run' },
            { data: 'path' },
            { data: 'change_type' },
            { data: 'compared_to' },
            { data: 'severity' },
        ],
    });
});
</script>
@endpush

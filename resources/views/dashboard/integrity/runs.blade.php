@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Integrity runs</h2>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table id="integrityRunsTable" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>UUID</th>
                            <th>Disk</th>
                            <th>Status</th>
                            <th>Severity</th>
                            <th>New</th>
                            <th>Modified</th>
                            <th>Deleted</th>
                            <th>Vs baseline</th>
                            <th>Files</th>
                            <th>Started</th>
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
    $('#integrityRunsTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'desc']],
        ajax: { url: '{{ app('shield')->route('integrity.runs') }}', data: { mode: 'dataTable' } },
        columns: [
            { data: 'id' },
            { data: 'uuid' },
            { data: 'disk' },
            { data: 'status' },
            { data: 'severity' },
            { data: 'new' },
            { data: 'modified' },
            { data: 'deleted' },
            { data: 'vs_known_good' },
            { data: 'files_total' },
            { data: 'started_at' },
        ],
    });
});
</script>
@endpush

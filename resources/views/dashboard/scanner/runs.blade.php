@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Scanner runs</h2>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table id="runsTable" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>UUID</th>
                            <th>Status</th>
                            <th>Files scanned</th>
                            <th>Findings</th>
                            <th>Critical</th>
                            <th>Started</th>
                            <th>Finished</th>
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
    $('#runsTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'desc']],
        ajax: { url: '{{ app('shield')->route('scanner.runs') }}', data: { mode: 'dataTable' } },
        columns: [
            { data: 'id' },
            { data: 'uuid' },
            { data: 'status' },
            { data: 'files_scanned' },
            { data: 'findings_count' },
            { data: 'findings_critical_count' },
            { data: 'started_at' },
            { data: 'finished_at' },
        ],
    });
});
</script>
@endpush

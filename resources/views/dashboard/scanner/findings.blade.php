@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Scanner findings</h2>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table id="findingsTable" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>UUID</th>
                            <th>Run</th>
                            <th>File path</th>
                            <th>Signature</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Created</th>
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
    $('#findingsTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'desc']],
        ajax: { url: '{{ app('shield')->route('scanner.findings') }}', data: { mode: 'dataTable' } },
        columns: [
            { data: 'id' },
            { data: 'uuid' },
            { data: 'run' },
            { data: 'file_path' },
            { data: 'signature_ref' },
            { data: 'severity' },
            { data: 'status' },
            { data: 'created_at' },
        ],
    });
});
</script>
@endpush

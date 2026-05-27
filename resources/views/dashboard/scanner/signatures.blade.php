@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Signatures</h2>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table id="signaturesTable" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Source</th>
                            <th>Source ref</th>
                            <th>Kind</th>
                            <th>Severity</th>
                            <th>Enabled</th>
                            <th>Version</th>
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
    $('#signaturesTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'asc']],
        ajax: { url: '{{ app('shield')->route('scanner.signatures') }}', data: { mode: 'dataTable' } },
        columns: [
            { data: 'id' },
            { data: 'name' },
            { data: 'source' },
            { data: 'source_ref' },
            { data: 'kind' },
            { data: 'severity' },
            { data: 'enabled' },
            { data: 'version' },
        ],
    });
});
</script>
@endpush

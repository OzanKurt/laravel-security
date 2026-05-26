@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <div class="row">
            <div class="col-lg-12">
                <div class="card shadow">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            @lang('shield::dashboard.acl') ({{ $aclCount }})
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="aclDataTable" class="table table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>@lang('shield::dashboard.columns.id')</th>
                                    <th>@lang('shield::dashboard.columns.value')</th>
                                    <th>@lang('shield::dashboard.columns.kind')</th>
                                    <th>@lang('shield::dashboard.columns.action')</th>
                                    <th>@lang('shield::dashboard.columns.source')</th>
                                    <th>@lang('shield::dashboard.columns.hit_count')</th>
                                    <th>@lang('shield::dashboard.columns.expires_at')</th>
                                    <th>@lang('shield::dashboard.columns.created_at')</th>
                                    <th>@lang('shield::dashboard.columns.actions')</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    var table = $('#aclDataTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        drawCallback: window.drawCallback,
        ajax: {
            url: '{{ app('shield')->route('acl.index') }}',
            data: { mode: 'dataTable' }
        },
        columns: [
            { data: 'id',         name: 'id',         className: 'all dtr-control' },
            { data: 'value',      name: 'value',       className: 'all' },
            { data: 'kind',       name: 'kind_id',     className: 'all' },
            { data: 'action',     name: 'action_id',   className: 'all' },
            { data: 'source',     name: 'source',      className: 'none' },
            { data: 'hit_count',  name: 'hit_count',   className: 'all' },
            { data: 'expires_at', name: 'expires_at',  className: 'none', orderable: false },
            { data: 'created_at', name: 'created_at',  className: 'none' },
            { data: 'actions',    name: 'actions',     className: 'all text-center', orderable: false, searchable: false }
        ],
        order: [[0, 'desc']],
        pageLength: 25
    });

    window.DataTables = window.DataTables || {};
    window.DataTables['aclDataTable'] = table;
});
</script>
@endpush

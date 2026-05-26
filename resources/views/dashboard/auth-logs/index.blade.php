@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <div class="row">
            <div class="col-lg-12">
                <div class="card shadow">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            @lang('shield::dashboard.auth_logs') ({{ $authLogsCount }})
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="authLogsDataTable" class="table table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>@lang('shield::dashboard.columns.id')</th>
                                    <th>@lang('shield::dashboard.columns.email')</th>
                                    <th>@lang('shield::dashboard.columns.is_successful')</th>
                                    <th>@lang('shield::dashboard.columns.ip')</th>
                                    <th>@lang('shield::dashboard.columns.user_agent')</th>
                                    <th>@lang('shield::dashboard.columns.referrer')</th>
                                    <th>@lang('shield::dashboard.columns.request_data')</th>
                                    <th>@lang('shield::dashboard.columns.meta_data')</th>
                                    <th>@lang('shield::dashboard.columns.created_at')</th>
                                    <th>@lang('shield::dashboard.columns.updated_at')</th>
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
    var table = $('#authLogsDataTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        drawCallback: window.drawCallback,
        ajax: {
            url: '{{ app('shield')->route('auth-logs.index') }}',
            data: { mode: 'dataTable' }
        },
        columns: [
            { data: 'id',            name: 'id',             className: 'all dtr-control' },
            { data: 'email',         name: 'email',           className: 'all' },
            { data: 'is_successful', name: 'is_successful',   className: 'all' },
            { data: 'ip',            name: 'ip',              className: 'all' },
            { data: 'user_agent',    name: 'user_agent',      className: 'none' },
            { data: 'referrer',      name: 'referrer',        className: 'none' },
            { data: 'request_data',  name: 'request_data',    className: 'none', orderable: false, searchable: false },
            { data: 'meta_data',     name: 'meta_data',       className: 'none', orderable: false, searchable: false },
            { data: 'created_at',    name: 'created_at',      className: 'none' },
            { data: 'updated_at',    name: 'updated_at',      className: 'none' }
        ],
        order: [[0, 'desc']],
        pageLength: 25
    });

    window.DataTables = window.DataTables || {};
    window.DataTables['authLogsDataTable'] = table;
});
</script>
@endpush

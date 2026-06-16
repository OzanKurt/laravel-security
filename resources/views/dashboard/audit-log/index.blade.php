@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        {{-- Filters card --}}
        <div class="row mb-3">
            <div class="col-lg-12">
                <div class="card shadow">
                    <div class="card-header">
                        @lang('shield::dashboard.audit_log_filters')
                    </div>
                    <div class="card-body">
                        <form id="auditLogFiltersForm">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label small">@lang('shield::dashboard.columns.kind')</label>
                                    <select id="filterKind" name="filter_kind_id" class="form-select form-select-sm">
                                        <option value="">- All -</option>
                                        @foreach($kinds as $kind)
                                            <option value="{{ $kind->id }}">{{ $kind->label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">@lang('shield::dashboard.columns.severity')</label>
                                    <select id="filterSeverity" name="filter_severity_id" class="form-select form-select-sm">
                                        <option value="">- All -</option>
                                        @foreach($severities as $sev)
                                            <option value="{{ $sev->id }}">{{ $sev->label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">@lang('shield::dashboard.columns.actor_type')</label>
                                    <select id="filterActorType" name="filter_actor_type" class="form-select form-select-sm">
                                        <option value="">- All -</option>
                                        <option value="user">user</option>
                                        <option value="system">system</option>
                                        <option value="cli">cli</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">@lang('shield::dashboard.columns.correlation_id')</label>
                                    <input type="text" id="filterCorrelationId" name="filter_correlation_id"
                                           class="form-control form-control-sm" placeholder="Correlation ID…">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small">@lang('shield::dashboard.from')</label>
                                    <input type="date" id="filterDateFrom" name="filter_date_from"
                                           class="form-control form-control-sm">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small">@lang('shield::dashboard.to')</label>
                                    <input type="date" id="filterDateTo" name="filter_date_to"
                                           class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-12 d-flex gap-2">
                                    <button type="button" id="applyFilters" class="btn btn-sm btn-primary">
                                        @lang('shield::dashboard.apply_filters')
                                    </button>
                                    <button type="button" id="resetFilters" class="btn btn-sm btn-secondary">
                                        @lang('shield::dashboard.reset_filters')
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Table card --}}
        <div class="row">
            <div class="col-lg-12">
                <div class="card shadow">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            @lang('shield::dashboard.audit_log') ({{ $auditLogCount }})
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="auditLogDataTable" class="table table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>@lang('shield::dashboard.columns.id')</th>
                                    <th>@lang('shield::dashboard.columns.kind')</th>
                                    <th>@lang('shield::dashboard.columns.severity')</th>
                                    <th>@lang('shield::dashboard.columns.actor_type')</th>
                                    <th>@lang('shield::dashboard.columns.description')</th>
                                    <th>@lang('shield::dashboard.columns.subject_type')</th>
                                    <th>@lang('shield::dashboard.columns.ip')</th>
                                    <th>@lang('shield::dashboard.columns.created_at')</th>
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
    var table = $('#auditLogDataTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        drawCallback: window.drawCallback,
        ajax: {
            url: '{{ app('shield')->route('audit-log.index') }}',
            data: function (d) {
                d.mode              = 'dataTable';
                d.filter_kind_id     = $('#filterKind').val();
                d.filter_severity_id = $('#filterSeverity').val();
                d.filter_actor_type  = $('#filterActorType').val();
                d.filter_correlation_id = $('#filterCorrelationId').val();
                d.filter_date_from   = $('#filterDateFrom').val();
                d.filter_date_to     = $('#filterDateTo').val();
            }
        },
        columns: [
            { data: 'id',             name: 'id',           className: 'all dtr-control' },
            { data: 'kind',           name: 'kind_id',       className: 'all' },
            { data: 'severity',       name: 'severity_id',   className: 'all' },
            { data: 'actor_type',     name: 'actor_type',    className: 'all' },
            { data: 'description',    name: 'description',   className: 'all' },
            { data: 'subject_type',   name: 'subject_type',  className: 'none' },
            { data: 'ip',             name: 'ip',            className: 'none' },
            { data: 'created_at',     name: 'created_at',    className: 'none' }
        ],
        order: [[0, 'desc']],
        pageLength: 25
    });

    window.DataTables = window.DataTables || {};
    window.DataTables['auditLogDataTable'] = table;

    $('#applyFilters').on('click', function () {
        table.ajax.reload();
    });

    $('#resetFilters').on('click', function () {
        $('#filterKind').val('');
        $('#filterSeverity').val('');
        $('#filterActorType').val('');
        $('#filterCorrelationId').val('');
        $('#filterDateFrom').val('');
        $('#filterDateTo').val('');
        table.ajax.reload();
    });
});
</script>
@endpush

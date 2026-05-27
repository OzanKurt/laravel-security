@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <div class="d-flex align-items-center justify-content-between">
            <h2 class="mb-0">WAF rules</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ruleModal">+ New rule</button>
        </div>
        <p class="text-muted small mt-2">
            Built-in rules can be toggled but not edited or deleted. User-added rules are fully editable.
        </p>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table id="wafRulesTable" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Source</th>
                            <th>Category</th>
                            <th>Target</th>
                            <th>Action</th>
                            <th>Severity</th>
                            <th>Enabled</th>
                            <th>Pattern</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>

        {{-- Add/edit modal --}}
        <div class="modal fade" id="ruleModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">New WAF rule</h5>
                        <button class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="ruleForm">
                        <div class="modal-body">
                            <input type="hidden" name="id">
                            <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" required></div>
                            <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                            <div class="row g-2">
                                <div class="col-md-3"><label class="form-label">Category</label>
                                    <select name="category" class="form-select" required>
                                        @foreach($categories as $c)<option value="{{ $c->name }}">{{ $c->label }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="col-md-3"><label class="form-label">Kind</label>
                                    <select name="kind" class="form-select" required>
                                        @foreach($kinds as $k)<option value="{{ $k->name }}">{{ $k->label }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="col-md-3"><label class="form-label">Target</label>
                                    <select name="target" class="form-select" required>
                                        @foreach($targets as $t)<option value="{{ $t->name }}">{{ $t->label }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="col-md-3"><label class="form-label">Action</label>
                                    <select name="action" class="form-select" required>
                                        @foreach($actions as $a)<option value="{{ $a->name }}">{{ $a->label }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-md-6"><label class="form-label">Severity</label>
                                    <select name="severity" class="form-select" required>
                                        @foreach($severities as $s)<option value="{{ $s->name }}">{{ $s->label }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="col-md-3"><label class="form-label">Score</label><input name="score" type="number" value="0" min="0" class="form-control"></div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check"><input type="checkbox" name="is_enabled" value="1" checked class="form-check-input"><label class="form-check-label">Enabled</label></div>
                                </div>
                            </div>
                            <div class="mt-3"><label class="form-label">Pattern</label><textarea name="pattern" class="form-control font-monospace" rows="3" required></textarea></div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#wafRulesTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'asc']],
        ajax: { url: '{{ app('shield')->route('rules.index') }}', data: { mode: 'dataTable' } },
        columns: [
            { data: 'id' },
            { data: 'name' },
            { data: 'source' },
            { data: 'category' },
            { data: 'target' },
            { data: 'action' },
            { data: 'severity' },
            { data: 'enabled', render: v => v ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' },
            { data: 'pattern', render: v => '<code>' + $('<div>').text(v).html() + '</code>' },
            {
                data: null,
                orderable: false,
                render: (row) => {
                    let btns = `<button class="btn btn-sm btn-outline-secondary toggle-rule" data-id="${row.id}">Toggle</button>`;
                    if (row.is_user) {
                        btns += ` <button class="btn btn-sm btn-outline-danger delete-rule" data-id="${row.id}">Delete</button>`;
                    }
                    return btns;
                },
            },
        ],
    });

    $('#ruleForm').on('submit', function (e) {
        e.preventDefault();
        const id = $(this).find('[name=id]').val();
        const payload = $(this).serialize();
        const url = id ? '{{ url(config("shield.dashboard.route_prefix") . "/rules") }}/' + id : '{{ app('shield')->route('rules.store') }}';
        const method = id ? 'PUT' : 'POST';
        $.ajax({ url, method, data: payload }).done(() => {
            $('#ruleModal').modal('hide');
            $(this).trigger('reset');
            table.ajax.reload();
        }).fail(xhr => alert(xhr.responseText));
    });

    $(document).on('click', '.toggle-rule', function () {
        const id = $(this).data('id');
        $.post('{{ url(config("shield.dashboard.route_prefix") . "/rules") }}/' + id + '/toggle')
            .done(() => table.ajax.reload(null, false));
    });

    $(document).on('click', '.delete-rule', function () {
        if (! confirm('Delete this rule?')) return;
        const id = $(this).data('id');
        $.ajax({ url: '{{ url(config("shield.dashboard.route_prefix") . "/rules") }}/' + id, method: 'DELETE' })
            .done(() => table.ajax.reload(null, false));
    });
});
</script>
@endpush

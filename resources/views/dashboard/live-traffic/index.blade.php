@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Live traffic</h2>
        <p class="text-muted small">
            Sampled per <code>shield.live_traffic.sample_rate</code>. Attacks are always 100% captured.
            Polling every 5s. Real-time mode (Reverb / Pusher / Soketi) available via <code>LS_LIVE_TRAFFIC_REALTIME=true</code>.
        </p>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table id="liveTrafficTable" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>IP</th>
                            <th>Country</th>
                            <th>Method</th>
                            <th>URL</th>
                            <th>Status</th>
                            <th>ms</th>
                            <th>Action</th>
                            <th>Captured</th>
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
    const table = $('#liveTrafficTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'desc']],
        ajax: { url: '{{ app('shield')->route('live-traffic.index') }}', data: { mode: 'dataTable' } },
        columns: [
            { data: 'id' },
            { data: 'ip' },
            { data: 'country_code' },
            { data: 'method' },
            { data: 'url' },
            { data: 'status_code' },
            { data: 'response_time_ms' },
            { data: 'action' },
            { data: 'created_at' },
        ],
    });

    setInterval(() => table.ajax.reload(null, false), 5000);
});
</script>
@endpush

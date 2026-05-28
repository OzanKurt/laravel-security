@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="mb-0">Webhook deliveries</h2>
                <p class="text-muted small mb-0">
                    Outbound calls to Central — webhook ingest, heartbeat, test pings.
                </p>
            </div>
            <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                <select name="status" class="form-select form-select-sm" style="width: 140px;">
                    <option value="">All statuses</option>
                    @foreach(['pending','success','failure','skipped','exhausted'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
                    @endforeach
                </select>
                <select name="operation" class="form-select form-select-sm" style="width: 180px;">
                    <option value="">All operations</option>
                    @foreach(['webhook_ingest','webhook_ingest_batch','heartbeat','test_ping'] as $o)
                        <option value="{{ $o }}" @selected(request('operation') === $o)>{{ $o }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-outline-primary">Filter</button>
            </form>
        </div>

        @if(session('status'))
            <div class="alert alert-info mt-3 small mb-0">{{ session('status') }}</div>
        @endif

        <div class="row g-3 mt-3">
            <div class="col-md-3">
                <div class="card shadow"><div class="card-body py-3">
                    <div class="text-muted small">Total (24h)</div>
                    <div class="fs-3">{{ number_format($stats['total_24h']) }}</div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card shadow"><div class="card-body py-3">
                    <div class="text-muted small">Success (24h)</div>
                    <div class="fs-3 text-success">{{ number_format($stats['success_24h']) }}</div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card shadow"><div class="card-body py-3">
                    <div class="text-muted small">Failure (24h)</div>
                    <div class="fs-3 text-warning">{{ number_format($stats['failure_24h']) }}</div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card shadow"><div class="card-body py-3">
                    <div class="text-muted small">Exhausted (24h)</div>
                    <div class="fs-3 text-danger">{{ number_format($stats['exhausted_24h']) }}</div>
                </div></div>
            </div>
        </div>

        <div class="card shadow mt-4">
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Dispatched</th>
                            <th>Operation</th>
                            <th>Status</th>
                            <th>HTTP</th>
                            <th>Attempt</th>
                            <th>Bytes</th>
                            <th>Duration</th>
                            <th>Reason</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($deliveries as $d)
                            @php
                                $color = match($d->status) {
                                    'success'   => 'bg-success',
                                    'pending'   => 'bg-info text-dark',
                                    'failure'   => 'bg-warning text-dark',
                                    'exhausted' => 'bg-danger',
                                    'skipped'   => 'bg-secondary',
                                    default     => 'bg-light text-dark',
                                };
                            @endphp
                            <tr>
                                <td class="ps-3 text-muted small">{{ $d->dispatched_at?->format('Y-m-d H:i:s') }}</td>
                                <td><code class="small">{{ $d->operation }}</code></td>
                                <td><span class="badge {{ $color }}">{{ $d->status }}</span></td>
                                <td>{{ $d->http_status ?: '—' }}</td>
                                <td>{{ $d->attempt_number }}/{{ $d->max_attempts }}</td>
                                <td>{{ $d->payload_bytes }}</td>
                                <td>{{ $d->duration_ms !== null ? $d->duration_ms . 'ms' : '—' }}</td>
                                <td class="text-muted small" style="max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $d->reason }}">
                                    {{ $d->reason ?? ($d->response_excerpt ? substr($d->response_excerpt, 0, 80) : '—') }}
                                </td>
                                <td class="pe-3">
                                    @if(in_array($d->status, ['failure','exhausted']) && $d->operation === 'webhook_ingest')
                                        <form method="POST" action="{{ app('shield')->route('webhook-deliveries.retry', ['id' => $d->id]) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-primary">Retry</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">
                                No deliveries yet — make sure premium is active + a queue worker is running.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $deliveries->links() }}</div>
    </div>
@endsection

@extends('shield::layouts.bootstrap.app')

@section('content')
    @php
        $stateKey = $state['state'] ?? 'no_key';
        $badge = [
            'valid'   => ['Active',          'bg-success'],
            'grace'   => ['Grace period',    'bg-warning text-dark'],
            'invalid' => ['Invalid',         'bg-danger'],
            'no_key'  => ['Not configured',  'bg-secondary'],
        ][$stateKey] ?? ['Unknown', 'bg-secondary'];
    @endphp

    <div class="mt-5">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="mb-0">Premium license</h2>
                <p class="text-muted small mb-0">
                    Runtime license check against
                    <code>{{ config('shield.premium.check_url') }}</code>.
                </p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge {{ $badge[1] }} fs-6 px-3 py-2">{{ $badge[0] }}</span>
                <form method="POST" action="{{ app('shield')->route('license.refresh') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm" {{ $hasKey ? '' : 'disabled' }}>
                        Refresh now
                    </button>
                </form>
                <form method="POST" action="{{ app('shield')->route('license.clear') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        Clear cache
                    </button>
                </form>
                <form method="POST" action="{{ app('shield')->route('license.test') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-success btn-sm" {{ $hasKey ? '' : 'disabled' }}>
                        Test connectivity
                    </button>
                </form>
            </div>
        </div>

        @if(session('status'))
            <div class="alert alert-info mt-3 small mb-0">{{ session('status') }}</div>
        @endif

        @if(! $hasKey)
            <div class="card shadow mt-4 border-secondary">
                <div class="card-body text-center py-5">
                    <h4 class="mb-2">No premium license configured</h4>
                    <p class="text-muted mb-4">
                        Add <code>LS_PREMIUM_LICENSE_KEY=…</code> to your <code>.env</code>
                        to unlock premium features. Core Shield (WAF, ACL, scanner, audit log,
                        threat feeds) keeps working without a license.
                    </p>
                    <a href="https://laravel-shield.ozankurt.com/pricing"
                       class="btn btn-primary btn-lg"
                       target="_blank"
                       rel="noopener">
                        See plans →
                    </a>
                </div>
            </div>
        @else
            @if($stateKey === 'grace')
                <div class="alert alert-warning mt-3 mb-0">
                    <strong>Central license API is unreachable.</strong>
                    Your premium features stay active until
                    <strong>{{ $state['grace_until'] ?? '-' }}</strong>.
                    Resolve before that to avoid feature deactivation.
                </div>
            @elseif($stateKey === 'invalid')
                <div class="alert alert-danger mt-3 mb-0">
                    <strong>License invalid:</strong>
                    {{ $state['reason'] ?? 'unknown reason' }}.
                    @if(! empty($state['message']))
                        <span class="text-muted small">({{ $state['message'] }})</span>
                    @endif
                </div>
            @endif

            <div class="row g-3 mt-3">
                <div class="col-md-6">
                    <div class="card shadow">
                        <div class="card-header">License</div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0">
                                <tr>
                                    <th class="ps-3" style="width: 200px;">Key</th>
                                    <td><code>{{ $maskedKey }}</code></td>
                                </tr>
                                <tr>
                                    <th class="ps-3">Plan</th>
                                    <td>{{ $state['plan'] ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th class="ps-3">Expires</th>
                                    <td>{{ $state['expires_at'] ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th class="ps-3">Last checked</th>
                                    <td>{{ $state['last_checked_at'] ?? '-' }}</td>
                                </tr>
                                @if(! empty($state['last_valid_at']))
                                    <tr>
                                        <th class="ps-3">Last known valid</th>
                                        <td>{{ $state['last_valid_at'] }}</td>
                                    </tr>
                                @endif
                                @if(! empty($state['grace_until']))
                                    <tr>
                                        <th class="ps-3">Grace until</th>
                                        <td>{{ $state['grace_until'] }}</td>
                                    </tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card shadow">
                        <div class="card-header">Usage</div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0">
                                <tr>
                                    <th class="ps-3" style="width: 200px;">Domain limit</th>
                                    <td>
                                        @if(isset($state['domain_limit']))
                                            {{ $state['domains_used'] ?? '?' }} / {{ $state['domain_limit'] }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th class="ps-3">Cache TTL</th>
                                    <td>{{ (int) config('shield.premium.cache_ttl') }} seconds</td>
                                </tr>
                                <tr>
                                    <th class="ps-3">Grace period</th>
                                    <td>{{ (int) config('shield.premium.grace_period_days') }} days</td>
                                </tr>
                                <tr>
                                    <th class="ps-3">Heartbeat</th>
                                    <td>
                                        @if(config('shield.premium.heartbeat.enabled'))
                                            <span class="badge bg-success">Enabled</span>
                                            every {{ (int) config('shield.premium.heartbeat.interval_minutes') }} min
                                        @else
                                            <span class="badge bg-secondary">Disabled</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            @if(! empty($state['features']))
                <div class="card shadow mt-4">
                    <div class="card-header">Features unlocked</div>
                    <div class="card-body">
                        @foreach($state['features'] as $feature)
                            <span class="badge bg-primary me-1 mb-1">{{ $feature }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection

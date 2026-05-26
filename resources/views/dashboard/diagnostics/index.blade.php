@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Diagnostics</h2>
        <p class="text-muted small">System inventory + OWASP-style configuration audit.</p>

        <div class="row g-3 mt-3">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header">System info</div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            @foreach($sysinfo as [$k, $v])
                                <tr><th class="ps-3">{{ $k }}</th><td>{{ $v }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header">Optional integrations</div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            @foreach($extensions as [$pkg, $status])
                                <tr>
                                    <th class="ps-3">{{ $pkg }}</th>
                                    <td>
                                        @if($status === 'installed')
                                            <span class="badge bg-success">Installed</span>
                                        @else
                                            <span class="badge bg-secondary">Not installed</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mt-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>Environment audit</span>
                <span>
                    Grade:
                    <span class="badge bg-{{ in_array($envGrade, ['A','B']) ? 'success' : (in_array($envGrade, ['C','D']) ? 'warning' : 'danger') }} fs-5">
                        {{ $envGrade }}
                    </span>
                </span>
            </div>
            <div class="card-body">
                @if(empty($envFindings))
                    <p class="text-success mb-0">All checks passed.</p>
                @else
                    <table class="table table-sm">
                        <thead><tr><th>Key</th><th>Severity</th><th>Recommendation</th></tr></thead>
                        <tbody>
                            @foreach($envFindings as $f)
                                <tr>
                                    <td><code>{{ $f['key'] }}</code></td>
                                    <td>
                                        @php $color = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'secondary'][$f['severity']] ?? 'secondary'; @endphp
                                        <span class="badge bg-{{ $color }}">{{ $f['severity'] }}</span>
                                    </td>
                                    <td>{{ $f['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
@endsection

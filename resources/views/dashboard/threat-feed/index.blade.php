@extends('shield::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <h2>Threat feeds</h2>
        <p class="text-muted small">
            Pulled via <code>shield:feed-sync</code> (scheduled). Configure providers via the
            <code>LS_*</code> environment variables.
        </p>

        <div class="card shadow mt-3">
            <div class="card-body">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Label</th>
                            <th>Available</th>
                            <th>Last run</th>
                            <th>Last status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td><code>{{ $row['name'] }}</code></td>
                                <td>{{ $row['label'] }}</td>
                                <td>
                                    @if($row['available'])
                                        <span class="badge bg-success">Yes</span>
                                    @else
                                        <span class="badge bg-secondary">No (configure env vars)</span>
                                    @endif
                                </td>
                                <td>{{ $row['last_run'] }}</td>
                                <td class="small">{{ $row['last_status'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

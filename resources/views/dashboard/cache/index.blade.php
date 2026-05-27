@extends('shield::layouts.bootstrap.app')

@section('content')
<div class="container mt-4">
    <h2>Cache</h2>
    <p class="text-muted">Shield cache keys. Clear individually or all at once. Use this after modifying ACL/rules if changes don't appear.</p>

    <div class="mb-3">
        <button class="btn btn-warning" id="clear-all">Clear all Shield cache</button>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead>
                <tr><th>Key</th><th>Present</th><th>Action</th></tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td><code>{{ $row['key'] }}</code></td>
                        <td>
                            @if($row['present'])
                                <span class="badge bg-success">Cached</span>
                            @else
                                <span class="badge bg-secondary">Empty</span>
                            @endif
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-danger clear-key" data-key="{{ $row['key'] }}">Clear</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('clear-all').addEventListener('click', async () => {
    const res = await fetch('{{ route('shield.cache.clear') }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: '*' }),
    });
    if (res.ok) location.reload();
});

document.querySelectorAll('.clear-key').forEach(btn => {
    btn.addEventListener('click', async () => {
        const key = btn.dataset.key;
        const res = await fetch('{{ route('shield.cache.clear') }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            body: JSON.stringify({ key }),
        });
        if (res.ok) location.reload();
    });
});
</script>
@endsection

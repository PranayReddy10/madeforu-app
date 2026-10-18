@extends('admin.layout')
@section('title', 'Redirects')
@section('actions')<a class="btn" href="{{ route('admin.redirects.create') }}">+ New redirect</a>@endsection
@section('content')
<p class="muted">Old addresses (from WordPress, or renamed products) that should send visitors and Google to a new page. Checked only when a page would otherwise be "not found".</p>
<form class="filters card" method="get">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search paths">
    <button class="btn ghost">Search</button>
</form>
<div class="card">
<table class="tbl">
    <thead><tr><th>From</th><th>To</th><th>Type</th><th>Hits</th><th></th></tr></thead>
    <tbody>
    @forelse ($redirects as $r)
        <tr>
            <td><code>{{ $r->from_path }}</code></td>
            <td><code>{{ $r->to_path }}</code></td>
            <td>{{ $r->status }}</td>
            <td>{{ $r->hits }}</td>
            <td class="nowrap"><a href="{{ route('admin.redirects.edit', $r) }}">Edit</a>
                <form method="post" action="{{ route('admin.redirects.destroy', $r) }}" class="inline">@csrf @method('DELETE')<button class="link danger" data-confirm="Delete this redirect?">Delete</button></form>
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No redirects.</td></tr>
    @endforelse
    </tbody>
</table>
{{ $redirects->links('admin.pagination') }}
</div>
@endsection

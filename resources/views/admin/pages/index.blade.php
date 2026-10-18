@extends('admin.layout')
@section('title', 'Pages')
@section('actions')<a class="btn" href="{{ route('admin.pages.create') }}">+ New page</a>@endsection
@section('content')
<div class="card">
<table class="tbl">
    <thead><tr><th>Title</th><th>URL</th><th>Status</th><th>Updated</th></tr></thead>
    <tbody>
    @forelse ($pages as $p)
        <tr>
            <td><a href="{{ route('admin.pages.edit', $p) }}"><strong>{{ $p->title }}</strong></a></td>
            <td><a href="{{ $p->url() }}" target="_blank" rel="noopener"><small>/{{ $p->slug }}/</small></a></td>
            <td>@if ($p->is_active)<span class="badge ok">Published</span>@else<span class="badge">Draft</span>@endif</td>
            <td><small>{{ $p->updated_at?->format('d M Y') }}</small></td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">No pages.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection

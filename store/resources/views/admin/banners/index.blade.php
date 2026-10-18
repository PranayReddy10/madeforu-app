@extends('admin.layout')
@section('title', 'Home page banners')
@section('actions')<a class="btn" href="{{ route('admin.banners.create') }}">+ New banner</a>@endsection
@section('content')
<div class="card">
<table class="tbl">
    <thead><tr><th></th><th>Title</th><th>Link</th><th>Order</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($banners as $b)
        <tr>
            <td><img class="thumb wide" src="{{ media_url($b->image) }}" alt="" loading="lazy"></td>
            <td><a href="{{ route('admin.banners.edit', $b) }}"><strong>{{ $b->title }}</strong></a><br><small class="muted">{{ $b->subtitle }}</small></td>
            <td><small>{{ $b->link }}</small></td>
            <td>{{ $b->position }}</td>
            <td>@if ($b->is_active)<span class="badge ok">Active</span>@else<span class="badge">Hidden</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No banners. The home page shows a default hero until you add one.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection

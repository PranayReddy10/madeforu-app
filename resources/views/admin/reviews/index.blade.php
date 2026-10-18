@extends('admin.layout')
@section('title', 'Reviews')
@section('content')
<div class="card">
<table class="tbl">
    <thead><tr><th>Product</th><th>Customer</th><th>Rating</th><th>Review</th><th>Date</th><th></th></tr></thead>
    <tbody>
    @forelse ($reviews as $r)
        <tr class="{{ $r->is_approved ? '' : 'pending' }}">
            <td>@if ($r->product)<a href="{{ $r->product->url() }}" target="_blank" rel="noopener">{{ $r->product->name }}</a>@else — @endif</td>
            <td>{{ $r->name }}</td>
            <td class="nowrap stars">{{ str_repeat('★', $r->rating) }}{{ str_repeat('☆', 5 - $r->rating) }}</td>
            <td>{{ \Illuminate\Support\Str::limit($r->body, 220) }}</td>
            <td class="nowrap"><small>{{ $r->created_at->format('d M Y') }}</small></td>
            <td class="nowrap">
                <form method="post" action="{{ route('admin.reviews.update', $r) }}" class="inline">@csrf @method('PUT')
                    <input type="hidden" name="is_approved" value="{{ $r->is_approved ? 0 : 1 }}">
                    <button class="btn small {{ $r->is_approved ? 'ghost' : '' }}">{{ $r->is_approved ? 'Hide' : 'Approve' }}</button>
                </form>
                <form method="post" action="{{ route('admin.reviews.destroy', $r) }}" class="inline">@csrf @method('DELETE')<button class="link danger" data-confirm="Delete this review?">Delete</button></form>
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No reviews yet.</td></tr>
    @endforelse
    </tbody>
</table>
{{ $reviews->links('admin.pagination') }}
</div>
@endsection

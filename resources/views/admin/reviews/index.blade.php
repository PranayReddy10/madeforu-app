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
            <td>{{ $r->name }}@if ($r->is_verified)<br><span class="badge ok">Verified buyer</span>@endif @if ($r->order)<br><a class="small" href="{{ route('admin.orders.show', $r->order) }}">{{ $r->order->number }}</a>@endif</td>
            <td class="nowrap stars">{{ str_repeat('★', $r->rating) }}{{ str_repeat('☆', 5 - $r->rating) }}</td>
            <td>{{ \Illuminate\Support\Str::limit($r->body, 220) }}
                @if ($r->photos)<div class="uploads">@foreach ($r->photoUrls() as $src)<a href="{{ $src }}" target="_blank" rel="noopener"><img src="{{ $src }}" alt="" loading="lazy"></a>@endforeach</div>@endif</td>
            <td class="nowrap"><small>{{ $r->created_at->format('d M Y') }}</small></td>
            <td class="nowrap">
                <form method="post" action="{{ route('admin.reviews.update', $r) }}" class="inline">@csrf @method('PUT')
                    <input type="hidden" name="is_approved" value="{{ $r->is_approved ? 0 : 1 }}">
                    <button class="btn small {{ $r->is_approved ? 'ghost' : '' }}">{{ $r->is_approved ? 'Hide' : 'Approve' }}</button>
                </form>
                @php $gUrl = (string) setting('reviews.google_url'); $rvWa = $r->order ? \App\Services\WhatsApp::number((string) $r->order->phone) : null; @endphp
                @if ($rvWa && preg_match('#^https://#i', $gUrl))
                    <a class="btn small ghost" target="_blank" rel="noopener" title="Ask on WhatsApp to post this review on Google"
                       href="https://wa.me/{{ $rvWa }}?text={{ rawurlencode('Hi '.\Illuminate\Support\Str::before(trim($r->name), ' ').'! Thank you so much for your review 💝'.($r->body ? "\n\n“".\Illuminate\Support\Str::limit($r->body, 400)."”" : '')."\n\nWould you post it on Google too? It really helps a small shop like ours: ".$gUrl) }}">Ask for Google review</a>
                @endif
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

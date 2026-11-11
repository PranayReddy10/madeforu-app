@extends('admin.layout')
@section('title', 'Coupons')
@section('actions')<a class="btn" href="{{ route('admin.coupons.create') }}">+ New coupon</a>@endsection
@section('content')
<div class="card">
<table class="tbl">
    <thead><tr><th>Code</th><th>Discount</th><th>Min. order</th><th>Used</th><th>Valid</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($coupons as $c)
        <tr>
            <td><a href="{{ route('admin.coupons.edit', $c) }}"><strong>{{ $c->code }}</strong></a></td>
            <td>{{ $c->type === 'percent' ? rtrim(rtrim(number_format($c->value, 2), '0'), '.').'%' : money($c->value) }}@if ($c->max_discount) <small class="muted">(max {{ money($c->max_discount) }})</small>@endif</td>
            <td>{{ money($c->min_subtotal) }}</td>
            <td>{{ $c->used }}{{ $c->usage_limit ? ' / '.$c->usage_limit : '' }}</td>
            <td><small>{{ $c->starts_at?->format('d M Y') ?? '—' }} → {{ $c->expires_at?->format('d M Y') ?? '—' }}</small></td>
            <td>@if ($c->is_active)<span class="badge ok">Active</span>@else<span class="badge">Off</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No coupons.</td></tr>
    @endforelse
    </tbody>
</table>
{{ $coupons->links('admin.pagination') }}
</div>
@endsection

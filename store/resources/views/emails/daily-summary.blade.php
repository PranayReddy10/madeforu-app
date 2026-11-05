@extends('emails.layout')
@section('preheader'){{ $s['orders_today'] }} orders today · {{ money($s['revenue_today']) }} · {{ $s['to_make']->count() }} to make @endsection
@section('body')
    <h1 style="font-size:20px;margin:0 0 12px">Today at {{ config('store.name') }} · {{ now()->format('D, j M') }}</h1>
    <table width="100%" cellpadding="0" cellspacing="8" role="presentation" style="margin:0 -8px">
        <tr>
            <td style="background:#ffe9f0;border-radius:10px;padding:12px"><small style="color:#4a5470">Orders today</small><br><b style="font-size:22px">{{ $s['orders_today'] }}</b>@if($s['cod_today'])<br><small>{{ $s['cod_today'] }} COD</small>@endif</td>
            <td style="background:#ffe9f0;border-radius:10px;padding:12px"><small style="color:#4a5470">Revenue today</small><br><b style="font-size:22px">{{ money($s['revenue_today']) }}</b></td>
            <td style="background:#f3f4f8;border-radius:10px;padding:12px"><small style="color:#4a5470">This month</small><br><b style="font-size:22px">{{ money($s['month_revenue']) }}</b></td>
        </tr>
    </table>

    <h2 style="font-size:16px;margin:18px 0 6px">To make ({{ $s['to_make']->count() }})</h2>
    @forelse($s['to_make']->take(15) as $o)
        <p style="margin:0 0 4px;font-size:14px"><a href="{{ route('admin.orders.show', $o) }}" style="color:#d42c5e">{{ $o->number }}</a> · {{ $o->name }} · {{ money($o->total) }} · <span style="color:#858ca3">{{ $o->created_at->diffForHumans() }}{{ $o->tracking_number ? ' · AWB booked' : '' }}</span></p>
    @empty
        <p style="color:#858ca3;font-size:14px">Nothing waiting. 🎉</p>
    @endforelse
    @if($s['to_hand_over'])<p style="font-size:14px">📦 {{ $s['to_hand_over'] }} {{ \Illuminate\Support\Str::plural('parcel', $s['to_hand_over']) }} booked with Delhivery — remember to request a pickup.</p>@endif

    @if($s['shipping_problems']->isNotEmpty())
        <h2 style="font-size:16px;margin:18px 0 6px;color:#d42c5e">Delhivery couldn't book</h2>
        @foreach($s['shipping_problems'] as $o)<p style="margin:0 0 4px;font-size:14px"><a href="{{ route('admin.orders.show', $o) }}" style="color:#d42c5e">{{ $o->number }}</a> — {{ \Illuminate\Support\Str::limit($o->shipment_error, 120) }}</p>@endforeach
    @endif

    @if($s['unpaid_online']->isNotEmpty())
        <h2 style="font-size:16px;margin:18px 0 6px">Online payment not completed ({{ $s['unpaid_online']->count() }})</h2>
        <p style="font-size:13px;color:#858ca3;margin:0 0 6px">They reached the payment page but didn't pay. A WhatsApp nudge often helps.</p>
        @foreach($s['unpaid_online'] as $o)<p style="margin:0 0 4px;font-size:14px"><a href="{{ route('admin.orders.show', $o) }}" style="color:#d42c5e">{{ $o->number }}</a> · {{ $o->name }} · {{ $o->phone }} · {{ money($o->total) }}</p>@endforeach
    @endif

    @if($s['low_stock']->isNotEmpty())
        <h2 style="font-size:16px;margin:18px 0 6px">Low stock</h2>
        @foreach($s['low_stock'] as [$name, $qty])<p style="margin:0 0 4px;font-size:14px">{{ $name }} — <b>{{ $qty ? $qty.' left' : 'sold out' }}</b></p>@endforeach
    @endif

    @if($s['reviews_waiting'])<p style="font-size:14px;margin-top:18px">⭐ {{ $s['reviews_waiting'] }} {{ \Illuminate\Support\Str::plural('review', $s['reviews_waiting']) }} waiting for approval.</p>@endif

    @include('emails.partials.button', ['url' => route('admin.dashboard'), 'label' => 'Open admin'])
@endsection

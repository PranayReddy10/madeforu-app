<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Production — {{ $order->number }}</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1c2844; margin: 0; padding: 24px; background: #fff; }
    header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 3px solid #f04577; padding-bottom: 12px; margin-bottom: 16px; }
    h1 { margin: 0; font-size: 26px; }
    .muted { color: #6b7390; }
    .item { border: 2px solid #eee3ea; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; page-break-inside: avoid; }
    .item h2 { margin: 0 0 4px; font-size: 18px; }
    .qty { display: inline-block; background: #1c2844; color: #fff; border-radius: 6px; padding: 2px 10px; font-weight: 700; margin-left: 6px; }
    .part { margin-top: 12px; }
    .part > .label { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #6b7390; font-weight: 700; }
    .photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-top: 6px; }
    .photos figure { margin: 0; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .photos img { width: 100%; height: 240px; object-fit: contain; background: #f5f5f5; display: block; }
    .photos figcaption { padding: 6px 8px; font-size: 13px; }
    .text { font-size: 22px; font-weight: 700; padding: 8px 12px; background: #fff5f8; border-left: 4px solid #f04577; border-radius: 6px; margin-top: 4px; white-space: pre-wrap; }
    .warn { color: #8a5a00; font-weight: 700; }
    .tick { display: inline-block; width: 18px; height: 18px; border: 2px solid #1c2844; border-radius: 4px; vertical-align: middle; margin-right: 6px; }
    .no-print { margin-bottom: 16px; }
    .btn { background: #f04577; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 14px; }
    .btn.ghost { background: #fff; color: #1c2844; border: 1px solid #ccc; }
    @media print { .no-print { display: none; } body { padding: 0; } a { color: inherit; text-decoration: none; } }
</style>
</head>
<body>
<div class="no-print">
    <button class="btn" onclick="window.print()">🖨 Print</button>
    <a class="btn ghost" href="{{ route('admin.orders.uploads', $order) }}">⬇ All photos (ZIP)</a>
    <a class="btn ghost" href="{{ route('admin.orders.show', $order) }}">← Back to order</a>
</div>
@php
    $days = max(1, (int) $order->items->map(fn ($i) => $i->product?->production_days ?? 3)->max());
    $shipBy = $order->created_at->copy()->addWeekdays($days);
@endphp
<header>
    <div>
        <h1>Order {{ $order->number }}</h1>
        <div class="muted">{{ $order->created_at->format('d M Y, g:i a') }} · {{ $order->name }} · {{ $order->city }}, {{ $order->state }}</div>
        @if ($order->notes)<div style="margin-top:6px"><b>Customer note:</b> {{ $order->notes }}</div>@endif
    </div>
    <div style="text-align:right">
        <div><b>Ship by {{ $shipBy->format('D, d M') }}</b></div>
        <div class="muted">{{ $order->payment_method === 'cod' ? 'Cash on delivery' : 'Prepaid' }}@if ($order->tracking_number) · AWB {{ $order->tracking_number }}@endif</div>
    </div>
</header>

@foreach ($sheet as $row)
    @php $item = $row['item']; @endphp
    <section class="item">
        <h2><span class="tick"></span>{{ $row['n'] }}. {{ $item->name }} <span class="qty">× {{ $item->qty }}</span></h2>
        @if ($item->options)<div class="muted">{{ collect($item->options)->map(fn ($v, $k) => "$k: $v")->implode(' · ') }}</div>@endif
        @foreach ($row['parts'] as $part)
            <div class="part">
                <div class="label">{{ $part['label'] }}</div>
                @if ($part['type'] === 'images')
                    <div class="photos">
                        @foreach ($part['photos'] as $p)
                            @php $u = $p['upload']; @endphp
                            <figure>
                                <a href="{{ route('admin.orders.upload', [$order, $u]) }}"><img src="{{ $u->fullUrl() }}" alt="{{ $p['slot'] }}"></a>
                                <figcaption><b>{{ $p['slot'] }}</b> · {{ $p['filename'] }}<br>
                                    <span class="muted">{{ $u->width ? $u->width.'×'.$u->height.' px' : $u->original_name }}</span>
                                    @if ($u->width && min($u->width, $u->height) < 800)<br><span class="warn">⚠ Low resolution — check with the customer</span>@endif
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                    @if (! $part['photos'])<div class="warn">No photos uploaded</div>@endif
                @elseif ($part['type'] === 'color')
                    <div class="text"><span style="display:inline-block;width:22px;height:22px;border-radius:4px;vertical-align:middle;background:{{ $part['value'] }}"></span> {{ $part['value'] }}</div>
                @else
                    <div class="text">@if (! empty($part['image']))<img src="{{ $part['image'] }}" alt="" style="width:46px;height:46px;object-fit:contain;vertical-align:middle;margin-right:8px;border:1px solid #ddd;border-radius:6px">@endif{{ $part['value'] !== '' ? $part['value'] : '—' }}</div>
                @endif
            </div>
        @endforeach
    </section>
@endforeach
</body>
</html>

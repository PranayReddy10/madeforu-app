{{-- Live parcel tracking for the customer (Track order page and My account → order). --}}
@php
    $live = $live ?? app(\App\Services\Shipping::class)->live($order);
    $order->refresh();
    $d = \App\Services\Shipping::describe($order, $live['status'] ?? null);
    $scans = array_reverse($live['scans'] ?? $order->shipment_scans ?? []);
    $expected = null;
    try { $expected = ! empty($live['expected']) ? \Illuminate\Support\Carbon::parse($live['expected']) : null; } catch (\Throwable) {}
    $last = $scans[0] ?? null;
    $steps = [1 => 'Confirmed', 2 => 'Being made', 3 => 'Shipped', 4 => 'Out for delivery', 5 => 'Delivered'];
    $delhivery = ($order->courier ?: 'Delhivery') === 'Delhivery';
    $place = fn ($l) => str_replace('_', ', ', (string) $l);   // Delhivery writes "Bengaluru_Indiranagar"
@endphp
@if (! in_array($order->status, ['cancelled', 'pending'], true))
<div class="trk trk-{{ $d['tone'] }}">
    <div class="trk-now">
        <span class="trk-label">{{ $d['label'] }}</span>
        @if ($d['step'] < 5 && $expected && $expected->isFuture())
            <span class="trk-eta">Expected by <b>{{ $expected->format('D, j M') }}</b></span>
        @elseif ($d['step'] === 5 && $order->delivered_at)
            <span class="trk-eta">on {{ $order->delivered_at->format('D, j M') }}</span>
        @endif
    </div>
    @if ($last && $d['step'] >= 3)
        <p class="trk-where">📍 {{ $place($last['location']) ?: 'In transit' }}@if (! empty($last['at'])) · {{ \Illuminate\Support\Carbon::parse($last['at'])->format('j M, g:i a') }}@endif</p>
    @endif
    <ol class="trk-steps">
        @foreach ($steps as $n => $label)
            <li class="{{ $n < $d['step'] ? 'done' : ($n === $d['step'] ? 'now' : '') }}"><span></span>{{ $label }}</li>
        @endforeach
    </ol>
    @if ($order->tracking_number)
        <p class="trk-awb">{{ $order->courier ?: 'Delhivery' }} tracking number <b>{{ $order->tracking_number }}</b>
            <button type="button" class="link-btn small" data-copy="{{ $order->tracking_number }}">Copy</button>
            @if ($delhivery) · <a href="{{ $order->trackingUrl() }}" target="_blank" rel="noopener">Open on Delhivery ↗</a>@endif
        </p>
    @else
        <p class="trk-awb muted">We’ll share the tracking number as soon as your gift is packed and handed to the courier.</p>
    @endif
    @if ($scans)
        <ol class="scans">
            @foreach (array_slice($scans, 0, 5) as $sc)
                <li><b>{{ $sc['status'] }}</b> <span class="muted">· {{ $place($sc['location']) }}@if (! empty($sc['at'])) · {{ \Illuminate\Support\Carbon::parse($sc['at'])->format('j M, g:i a') }}@endif</span>@if (! empty($sc['note']))<br><small class="muted">{{ $sc['note'] }}</small>@endif</li>
            @endforeach
        </ol>
        @if (count($scans) > 5)
            <details class="trk-more"><summary>All {{ count($scans) }} updates</summary>
                <ol class="scans">
                    @foreach (array_slice($scans, 5) as $sc)
                        <li><b>{{ $sc['status'] }}</b> <span class="muted">· {{ $place($sc['location']) }}@if (! empty($sc['at'])) · {{ \Illuminate\Support\Carbon::parse($sc['at'])->format('j M, g:i a') }}@endif</span></li>
                    @endforeach
                </ol>
            </details>
        @endif
    @endif
</div>
@endif

<dl class="totals">
    <div><dt>Subtotal</dt><dd>{{ money($totals['subtotal']) }}</dd></div>
    @if($totals['discount'] > 0)<div class="good"><dt>Discount @if($totals['coupon'])({{ $totals['coupon'] }})@endif</dt><dd>−{{ money($totals['discount']) }}</dd></div>@endif
    <div><dt>Shipping @if($totals['shipping_live'] ?? false)<small class="muted">· Delhivery rate to your PIN</small>@elseif($totals['shipping_estimate'] ?? false)<small class="muted">· estimate, exact charge after your PIN</small>@endif</dt><dd>{{ $totals['shipping'] > 0 ? money($totals['shipping']) : 'Free' }}</dd></div>
    @if(($totals['cod_fee'] ?? 0) > 0)<div><dt>Cash on delivery charges</dt><dd>{{ money($totals['cod_fee']) }}</dd></div>@endif
    <div class="grand"><dt>Total</dt><dd>{{ money($totals['total']) }}</dd></div>
</dl>

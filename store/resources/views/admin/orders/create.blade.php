@extends('admin.layout')
@section('title', 'New order')
@section('actions')
    <a class="btn small ghost" href="{{ route('admin.orders.index') }}">← Orders</a>
@endsection
@section('content')
<form method="post" action="{{ route('admin.orders.store') }}" class="mo" data-manual-order
      data-catalog="{{ json_encode($catalog) }}" data-old-items="{{ json_encode(old('items', [])) }}"
      data-free-over="{{ $freeOver }}" data-shipping-fee="{{ $shippingFee }}" data-cod-fixed="{{ $codFixed }}" data-cod-percent="{{ $codPercent }}">
    @csrf
    <p class="muted small">For orders taken on WhatsApp, Instagram or the phone. The customer’s photos stay in the chat — nothing to upload; the production sheet says where to find them. Once saved it is a normal confirmed order: invoice, stock, Delhivery, Sales and the sale panel.</p>

    <div class="grid2">
        <section class="card">
            <h2>Customer</h2>
            <label>Order came from
                <select name="source">@foreach (\App\Http\Controllers\Admin\ManualOrderController::SOURCES as $k => $l)<option value="{{ $k }}" @selected(old('source', 'whatsapp') === $k)>{{ $l }}</option>@endforeach</select>
            </label>
            <div class="row2">
                <label>Name <input name="name" value="{{ old('name') }}" required maxlength="120"></label>
                <label>Mobile <input name="phone" value="{{ old('phone') }}" required inputmode="numeric" maxlength="13" placeholder="10-digit mobile"></label>
            </div>
            <label>Email <small class="muted">(optional — for the invoice &amp; order emails)</small><input type="email" name="email" value="{{ old('email') }}" maxlength="160"></label>
            <label>House / flat, street <input name="address_line1" value="{{ old('address_line1') }}" required maxlength="255"></label>
            <label>Area, landmark <small class="muted">(optional)</small><input name="address_line2" value="{{ old('address_line2') }}" maxlength="255"></label>
            <div class="row3">
                <label>PIN code <input name="pincode" value="{{ old('pincode') }}" required inputmode="numeric" maxlength="6"></label>
                <label>City <input name="city" value="{{ old('city') }}" required maxlength="80"></label>
                <label>State
                    <select name="state" required><option value="">Select</option>@foreach ($states as $s)<option @selected(old('state', 'Telangana') === $s)>{{ $s }}</option>@endforeach</select>
                </label>
            </div>
        </section>

        <section class="card">
            <h2>Payment</h2>
            <div class="mo-pay">
                @foreach (\App\Http\Controllers\Admin\ManualOrderController::PAYMENTS as $k => $l)
                    <label><input type="radio" name="payment" value="{{ $k }}" @checked(old('payment', 'upi') === $k)> {{ $l }}</label>
                @endforeach
            </div>
            <label data-paid-only>Payment reference <small class="muted">(UTR / transaction ID, optional)</small><input name="payment_ref" value="{{ old('payment_ref') }}" maxlength="100"></label>
            <div class="row2">
                <label>Shipping (₹) <input type="number" name="shipping" step="0.01" min="0" value="{{ old('shipping') }}" placeholder="auto" data-mo-charge></label>
                <label data-cod-only>COD charges (₹) <input type="number" name="cod_fee" step="0.01" min="0" value="{{ old('cod_fee') }}" placeholder="auto" data-mo-charge></label>
            </div>
            <div class="row3">
                <label>Discount (₹) <input type="number" name="discount" step="0.01" min="0" value="{{ old('discount') }}" placeholder="0" data-mo-charge></label>
                <label>Extra charge (₹) <input type="number" name="extra_charge" step="0.01" min="0" value="{{ old('extra_charge') }}" placeholder="0" data-mo-charge></label>
                <label>For <input name="extra_charge_label" value="{{ old('extra_charge_label') }}" maxlength="80" placeholder="Rush, gift wrap…"></label>
            </div>
            <label>Notes for making <small class="muted">(optional)</small><textarea name="notes" rows="2" maxlength="1000" placeholder="e.g. Use the 2nd photo she sent on 5 Nov">{{ old('notes') }}</textarea></label>
            <table class="tbl compact mo-totals">
                <tr><td>Items</td><td class="right" data-t="sub">₹0</td></tr>
                <tr><td>Discount</td><td class="right" data-t="disc">−₹0</td></tr>
                <tr><td>Shipping</td><td class="right" data-t="ship">₹0</td></tr>
                <tr data-cod-only><td>COD charges</td><td class="right" data-t="cod">₹0</td></tr>
                <tr><td>Extra</td><td class="right" data-t="extra">₹0</td></tr>
                <tr><th>Total</th><th class="right" data-t="total">₹0</th></tr>
            </table>
        </section>
    </div>

    <section class="card">
        <h2>Items</h2>
        <div data-mo-lines></div>
        <button type="button" class="btn small ghost" data-mo-add>+ Add product</button>
        <datalist id="mo-products">@foreach ($catalog as $p)<option value="{{ $p['name'] }}"></option>@endforeach</datalist>
    </section>

    <section class="card mo-save">
        <label class="check"><input type="checkbox" name="notify" value="1" @checked(old('notify', true))> Send the customer the order confirmation (email if given, &amp; WhatsApp)</label>
        <button class="btn">Create order</button>
    </section>
</form>
@endsection

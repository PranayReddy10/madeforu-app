@extends('admin.layout')
@section('title', $coupon->exists ? 'Edit coupon '.$coupon->code : 'New coupon')
@section('content')
<form method="post" class="card narrow" action="{{ $coupon->exists ? route('admin.coupons.update', $coupon) : route('admin.coupons.store') }}">
    @csrf
    @if ($coupon->exists) @method('PUT') @endif
    <div class="row3">
        <label>Code <input name="code" value="{{ old('code', $coupon->code) }}" required style="text-transform:uppercase"></label>
        <label>Type
            <select name="type">
                <option value="percent" @selected(old('type', $coupon->type) === 'percent')>Percentage</option>
                <option value="fixed" @selected(old('type', $coupon->type) === 'fixed')>Fixed amount (₹)</option>
            </select>
        </label>
        <label>Value <input type="number" step="0.01" min="0" name="value" value="{{ old('value', $coupon->value) }}" required></label>
    </div>
    <div class="row3">
        <label>Minimum order (₹) <input type="number" step="0.01" min="0" name="min_subtotal" value="{{ old('min_subtotal', $coupon->min_subtotal) }}"></label>
        <label>Max discount (₹) <input type="number" step="0.01" min="0" name="max_discount" value="{{ old('max_discount', $coupon->max_discount) }}"></label>
        <label>Usage limit <input type="number" min="1" name="usage_limit" value="{{ old('usage_limit', $coupon->usage_limit) }}" placeholder="unlimited"></label>
    </div>
    <div class="row2">
        <label>Starts <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $coupon->starts_at?->format('Y-m-d\TH:i')) }}"></label>
        <label>Expires <input type="datetime-local" name="expires_at" value="{{ old('expires_at', $coupon->expires_at?->format('Y-m-d\TH:i')) }}"></label>
    </div>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $coupon->is_active))> Active</label>
    <label class="check"><input type="checkbox" name="show_on_cart" value="1" @checked(old('show_on_cart', $coupon->show_on_cart))> Show on the cart page as an offer <small class="muted">— customers see it under “Available offers” and apply it with one tap. Leave unticked for private codes (influencers, apologies, staff).</small></label>
    <button class="btn">Save coupon</button>
</form>
@if ($coupon->exists)
<form method="post" action="{{ route('admin.coupons.destroy', $coupon) }}" class="card narrow danger-zone">@csrf @method('DELETE')<button class="btn danger" data-confirm="Delete this coupon?">Delete coupon</button></form>
@endif
@endsection

<?php

namespace App\Services;

use App\Models\AbandonedCart;
use App\Models\CustomerUpload;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Abandoned carts: once a shopper types an email or phone at checkout, the cart is remembered so a
 * reminder can bring them back to it. Ordering (from any device, by email or phone) closes it.
 */
class CartRecovery
{
    public function __construct(private Cart $cart) {}

    public static function enabled(): bool
    {
        return (bool) setting('abandoned.enabled', true);
    }

    /** @param array{name?: ?string, email?: ?string, phone?: ?string} $contact */
    public function capture(array $contact): ?AbandonedCart
    {
        $raw = $this->cart->raw();
        $email = filter_var($contact['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
        $digits = substr(preg_replace('/\D/', '', (string) ($contact['phone'] ?? '')), -10);
        $phone = preg_match('/^[6-9]\d{9}$/', $digits) ? $digits : null;
        if (! self::enabled() || ! $raw || (! $email && ! $phone)) {
            return null;
        }
        $cart = AbandonedCart::firstOrNew(['session_id' => session()->getId(), 'recovered_at' => null]);
        if (! $cart->exists) {
            $cart->token = (string) Str::uuid();
        }
        $cart->fill([
            'user_id' => auth()->id(),
            'name' => Str::limit(trim((string) ($contact['name'] ?? '')), 120, '') ?: $cart->name,
            'email' => $email ?: $cart->email,
            'phone' => $phone ?: $cart->phone,
            'lines' => array_values($raw),
            'value' => $this->cart->subtotal($this->cart->lines()),
        ])->save();

        return $cart;
    }

    /** The order closes any open cart from this browser, or from the same email or phone. */
    public function markRecovered(Order $order): void
    {
        AbandonedCart::whereNull('recovered_at')
            ->where(fn ($q) => $q->where('session_id', session()->getId())
                ->orWhere(fn ($q) => $q->whereNotNull('email')->where('email', $order->email))
                ->orWhere(fn ($q) => $q->whereNotNull('phone')->where('phone', $order->phone)))
            ->where('created_at', '>=', now()->subDays(14))
            ->update(['recovered_order_id' => $order->id, 'recovered_at' => now()]);
    }

    /** Put the saved cart back into this browser, customer photos included. */
    public function restore(AbandonedCart $cart): void
    {
        $lines = $this->cart->raw();
        foreach ($cart->lines as $l) {
            $id = substr(sha1($l['product_id'].'|'.($l['variant_id'] ?? '').'|'.json_encode($l['custom'] ?? [])), 0, 16);
            $lines[$id] ??= $l;
        }
        session(['cart.lines' => $lines]);
        // Photos are tied to the browser that uploaded them; hand them to this one.
        $tokens = collect($cart->lines)->pluck('custom')->flatten()->filter(fn ($t) => is_string($t) && strlen($t) === 36)->all();
        if ($tokens) {
            CustomerUpload::whereIn('token', $tokens)->whereNull('order_item_id')->update(['session_id' => session()->getId()]);
        }
        $cart->update(['session_id' => session()->getId()]);
        session(['checkout.prefill' => array_filter(['name' => $cart->name, 'email' => $cart->email, 'phone' => $cart->phone])]);
        session(['utm' => array_merge(session('utm', []), ['utm_source' => 'abandoned_cart', 'utm_medium' => 'reminder'])]);
    }
}

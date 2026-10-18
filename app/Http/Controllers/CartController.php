<?php

namespace App\Http\Controllers;

use App\Models\AbandonedCart;
use App\Models\Product;
use App\Services\Analytics;
use App\Services\Cart;
use App\Services\CartRecovery;
use App\Services\Seo;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private Cart $cart) {}

    public function index(Seo $seo)
    {
        $seo->title('Cart')->noindex();
        $lines = $this->cart->lines();

        return view('cart', ['lines' => $lines, 'totals' => $this->cart->totals($lines)]);
    }

    public function add(Request $request, Product $product)
    {
        abort_unless($product->is_active, 404);
        $data = $request->validate([
            'variant_id' => 'nullable|integer',
            'qty' => 'nullable|integer|min:1|max:99',
            'custom' => 'nullable|array',
            'custom_each' => 'nullable|boolean',
            'items' => 'nullable|array|max:98',
            'items.*.custom' => 'nullable|array',
        ]);
        $qty = (int) ($data['qty'] ?? 1);
        if ($qty > 1 && $request->boolean('custom_each')) {
            // "Different for each": item 1 uses custom[], items 2…N use items[1..N-1][custom].
            $customs = [$data['custom'] ?? []];
            for ($i = 1; $i < $qty; $i++) {
                $customs[] = $data['items'][$i]['custom'] ?? [];
            }
            $this->cart->addEach($product, $data['variant_id'] ?? null, $customs);
        } else {
            $this->cart->add($product, $data['variant_id'] ?? null, $qty, $data['custom'] ?? []);
        }

        $line = $this->cart->lines()->last();
        if ($request->expectsJson()) {
            return response()->json([
                'count' => $this->cart->count(),
                'message' => $product->name.' added to your cart.',
                'event' => ['event' => 'add_to_cart', 'ecommerce' => [
                    'currency' => 'INR', 'value' => $line?->total,
                    'items' => [app(Analytics::class)->item($product, $line?->unit, (int) ($data['qty'] ?? 1), $line?->variant?->label())],
                ]],
            ]);
        }

        return $request->input('buy_now') ? redirect()->route('checkout') : redirect()->route('cart')->with('status', $product->name.' added to your cart.');
    }

    public function update(Request $request)
    {
        foreach ((array) $request->input('qty', []) as $line => $qty) {
            $this->cart->update((string) $line, (int) $qty);
        }

        return redirect()->route('cart');
    }

    public function remove(string $line)
    {
        $this->cart->remove($line);

        return redirect()->route('cart')->with('status', 'Item removed.');
    }

    /** Checkout calls this as the shopper types their email/phone, so the cart can be remembered. */
    public function capture(Request $request, CartRecovery $recovery)
    {
        $data = $request->validate(['name' => 'nullable|string|max:120', 'email' => 'nullable|string|max:160', 'phone' => 'nullable|string|max:20']);

        return response()->json(['saved' => (bool) $recovery->capture($data)]);
    }

    /** Link in the reminder: put the cart back and go to checkout. */
    public function recover(string $token, CartRecovery $recovery)
    {
        $cart = AbandonedCart::where('token', $token)->first();
        if (! $cart || $cart->recovered_at || $cart->created_at->lt(now()->subDays(14))) {
            return redirect()->route('cart')->with('error', $cart?->recovered_at ? 'That order has already been placed. Thank you!' : 'That cart link has expired — have a look around!');
        }
        $recovery->restore($cart);
        if ($code = request()->query('coupon')) {
            $this->cart->applyCoupon((string) $code);
        }

        return redirect()->route('checkout')->with('status', 'Welcome back! Your cart is just as you left it.');
    }

    public function unsubscribe(string $token)
    {
        $cart = AbandonedCart::where('token', $token)->firstOrFail();
        AbandonedCart::where(fn ($q) => $q->where('id', $cart->id)
            ->orWhere(fn ($q) => $q->whereNotNull('email')->where('email', $cart->email))
            ->orWhere(fn ($q) => $q->whereNotNull('phone')->where('phone', $cart->phone)))
            ->update(['unsubscribed_at' => now()]);

        return redirect()->route('home')->with('status', 'Done — we won\'t send you cart reminders again.');
    }

    public function coupon(Request $request)
    {
        $request->validate(['code' => 'required|string|max:40']);
        $error = $this->cart->applyCoupon($request->code);

        return back()->with($error ? 'error' : 'status', $error ?: 'Coupon applied.');
    }

    public function removeCoupon()
    {
        $this->cart->removeCoupon();

        return back();
    }
}

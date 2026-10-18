<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Analytics;
use App\Services\Cart;
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
        ]);
        $this->cart->add($product, $data['variant_id'] ?? null, (int) ($data['qty'] ?? 1), $data['custom'] ?? []);

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

<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\Invoice;
use App\Services\Seo;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request, Seo $seo)
    {
        $seo->title('My account')->noindex();
        if (! $request->user()) {
            return view('account.guest');
        }

        return view('account.index', ['orders' => $request->user()->orders()->with('items')->paginate(10)]);
    }

    public function order(Request $request, string $number, Seo $seo)
    {
        $order = Order::where('number', $number)->where('user_id', $request->user()->id)->with('items')->firstOrFail();
        $seo->title('Order '.$order->number)->noindex();

        return view('account.order', compact('order'));
    }

    public function track(Request $request, Seo $seo)
    {
        $seo->title('Track your order')->description('Track your '.config('store.name').' order with your order number and phone number.');
        $order = null;
        if ($request->filled(['number', 'phone'])) {
            $order = Order::where('number', strtoupper(trim($request->number)))
                ->where('phone', substr(preg_replace('/\D/', '', $request->phone), -10))->with('items')->first();
            if (! $order) {
                session()->flash('error', 'We could not find an order with those details.');
            }
        }

        return view('account.track', compact('order'));
    }

    /**
     * Invoice PDF for the customer: from a signed link (email, WhatsApp), the browser that placed
     * the order, or the account it belongs to.
     */
    public function invoice(Request $request, string $number)
    {
        $order = Order::where('number', $number)->with('items.product')->firstOrFail();
        $allowed = $request->hasValidSignature()
            || in_array($order->number, session('placed_orders', []), true)
            || ($request->user() && ($request->user()->id === $order->user_id || $request->user()->is_admin));
        abort_unless($allowed, 403);
        abort_unless($order->invoice_number || in_array($order->status, ['confirmed', 'in_production', 'shipped', 'delivered'], true), 404);

        return Invoice::pdf($order)->download(Invoice::filename($order));
    }

    public function wishlist(Request $request, Seo $seo)
    {
        $seo->title('Wishlist')->noindex();

        return view('account.wishlist', ['products' => $request->user()->wishlist()->with(['images', 'variants', 'customizationFields'])->get()]);
    }

    public function toggleWishlist(Request $request, Product $product)
    {
        $res = $request->user()->wishlist()->toggle($product->id);
        $added = ! empty($res['attached']);

        return $request->expectsJson() ? response()->json(['added' => $added]) : back();
    }
}

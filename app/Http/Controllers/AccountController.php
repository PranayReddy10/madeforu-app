<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
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

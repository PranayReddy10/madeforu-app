<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Services\Analytics;
use App\Services\Delhivery;
use App\Services\Seo;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function show(string $slug, Seo $seo, Analytics $analytics)
    {
        $product = Product::active()->where('slug', $slug)
            ->with(['images', 'variants', 'productAttributes', 'customizationFields', 'primaryCategory.parent', 'reviews', 'categories'])
            ->firstOrFail();
        Product::whereKey($product->id)->increment('views');

        $seo->forProduct($product);
        $analytics->viewItem($product);

        $catIds = $product->categories->pluck('id');
        $related = Product::active()->whereKeyNot($product->id)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $catIds))
            ->with(['images', 'variants', 'primaryCategory', 'customizationFields'])
            ->inRandomOrder()->take(8)->get();

        $variants = $product->variants->map(fn ($v) => [
            'id' => $v->id,
            'options' => $v->options,
            'price' => $v->currentPrice(),
            'regular' => (float) $v->price,
            'in_stock' => $v->in_stock,
            'stock' => $v->stock_qty,
            'image' => $v->image ? media_url($v->image) : null,
            'sku' => $v->sku,
        ])->values();

        return view('product', compact('product', 'related', 'variants'));
    }

    /** "Check delivery" box on the product page and the PIN field at checkout. */
    public function deliveryCheck(Request $request, Delhivery $delhivery)
    {
        $pin = preg_replace('/\D/', '', (string) $request->query('pin'));
        if (! preg_match('/^[1-9]\d{5}$/', $pin)) {
            return response()->json(['ok' => false, 'message' => 'Please enter a valid 6-digit PIN code.'], 422);
        }
        $days = max(1, (int) $request->query('days', 3));
        $from = now()->addWeekdays($days)->addDays(2)->format('D, j M');
        $to = now()->addWeekdays($days)->addDays(6)->format('D, j M');
        $res = $delhivery->pincode($pin);
        $codOn = (bool) setting('cod_enabled');
        if ($res === null) {   // not configured or Delhivery unreachable: give the usual estimate
            return response()->json(['ok' => true, 'pin' => $pin, 'cod' => $codOn, 'message' => "Delivery by {$from} – {$to}"]);
        }
        if (! $res['serviceable']) {
            return response()->json(['ok' => false, 'pin' => $pin, 'cod' => false, 'message' => "Sorry, we don't deliver to {$pin} yet."]);
        }

        return response()->json([
            'ok' => true, 'pin' => $pin, 'cod' => $codOn && $res['cod'], 'city' => $res['city'],
            'message' => "Delivery to {$pin}".($res['city'] ? " ({$res['city']})" : '')." by {$from} – {$to}"
                .($codOn ? ($res['cod'] ? ' · Cash on delivery available' : ' · Prepaid only') : ''),
        ]);
    }

    public function review(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'rating' => 'required|integer|between:1,5',
            'body' => 'nullable|string|max:2000',
        ]);
        Review::create($data + ['product_id' => $product->id, 'user_id' => $request->user()?->id, 'is_approved' => false]);

        return back()->with('status', 'Thank you! Your review will appear once it is approved.');
    }
}

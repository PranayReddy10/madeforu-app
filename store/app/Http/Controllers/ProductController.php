<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Services\Analytics;
use App\Services\Cart;
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
        request()->attributes->set('visit_product', $product->id);

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

        $ratingBars = $product->reviews->countBy('rating')->all();

        return view('product', compact('product', 'related', 'variants', 'ratingBars'));
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

        $charge = $this->deliveryCharge($request, $pin, $codOn && $res['cod']);

        return response()->json([
            'ok' => true, 'pin' => $pin, 'cod' => $codOn && $res['cod'], 'city' => $res['city'], 'charge' => $charge['amount'] ?? null,
            'message' => "Delivery to {$pin}".($res['city'] ? " ({$res['city']})" : '')." by {$from} – {$to}"
                .($charge ? ' · '.$charge['text'] : '')
                .($codOn ? ($res['cod'] ? ' · Cash on delivery available' : ' · Prepaid only') : ''),
        ]);
    }

    /** The live Delhivery charge for one of this product to the PIN (product page), when live rates are on. */
    private function deliveryCharge(Request $request, string $pin, bool $codPossible): ?array
    {
        $product = $request->integer('item') ? Product::active()->find($request->integer('item')) : null;
        if (! $product || ! Cart::liveShipping()) {
            return null;
        }
        $price = (float) ($product->isOnSale() ? $product->sale_price : $product->price);
        $freeOver = (float) setting('free_shipping_over');
        if ($freeOver > 0 && $price >= $freeOver) {
            return ['amount' => 0.0, 'text' => 'Free delivery'];
        }
        $quote = Cart::shippingQuote([[$product, 1]], $pin, $codPossible, $price);
        if (! $quote) {
            return null;
        }
        $text = 'Delivery charge '.money($quote['shipping']);
        if ($codPossible && $quote['cod'] !== null && $quote['cod'] > 0) {
            $text .= ' (+'.money($quote['cod']).' for cash on delivery)';
        }
        if ($freeOver > 0) {
            $text .= ' · free over '.money($freeOver);
        }

        return ['amount' => $quote['shipping'], 'text' => $text];
    }

    public function review(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'rating' => 'required|integer|between:1,5',
            'body' => 'nullable|string|max:2000',
            'photos' => 'nullable|array|max:3',
            'photos.*' => 'image|max:10240',
            'website' => 'prohibited',   // honeypot: people never see this field, bots fill it
        ]);
        $order = Review::boughtBy($product->id, $request->user());
        Review::create([
            'product_id' => $product->id, 'user_id' => $request->user()?->id, 'order_id' => $order?->id,
            'name' => $data['name'], 'rating' => $data['rating'], 'body' => $data['body'] ?? null,
            'photos' => Review::storePhotos($request->file('photos', [])) ?: null,
            'is_verified' => (bool) $order,
            'is_approved' => $order && setting('reviews.auto_approve') && $data['rating'] >= 4,
        ]);

        return back()->with('status', 'Thank you! Your review will appear once it is approved.');
    }
}

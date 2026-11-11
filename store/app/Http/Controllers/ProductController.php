<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Services\Analytics;
use App\Services\Cart;
use App\Services\Delhivery;
use App\Services\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ProductController extends Controller
{
    public function show(string $slug, Seo $seo, Analytics $analytics, Cart $cart)
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
        $fromLine = self::prefillFromCartLine($product, $cart);
        $together = self::boughtTogether($product);
        $shipNudge = self::shipNudge($product, $cart);

        return view('product', compact('product', 'related', 'variants', 'ratingBars', 'together', 'shipNudge', 'fromLine'));
    }

    /**
     * "Different for each" from the cart: open the product with that line's choices (size, print side,
     * gift box…) already picked, so only the photos and text need doing for the new item.
     */
    private static function prefillFromCartLine(Product $product, Cart $cart): ?object
    {
        $id = request()->query('from_line');
        $line = is_string($id) ? $cart->lines()->firstWhere('id', $id) : null;
        if (! $line || $line->product->id !== $product->id) {
            return null;
        }
        $choices = collect($line->details)->filter(fn ($d) => in_array($d->type, ['select', 'choice'], true))
            ->mapWithKeys(fn ($d) => [$d->field_id => $d->value])->all();
        if ($choices && ! session()->hasOldInput()) {
            session()->now('_old_input', ['custom' => $choices]);
        }

        return $line;
    }

    /** Products most often in the same orders as this one (up to 3). */
    private static function boughtTogether(Product $product): Collection
    {
        $orderIds = OrderItem::where('product_id', $product->id)->latest('id')->limit(500)->pluck('order_id');
        if ($orderIds->isEmpty()) {
            return collect();
        }
        $ids = OrderItem::whereIn('order_id', $orderIds)->where('product_id', '!=', $product->id)->whereNotNull('product_id')
            ->selectRaw('product_id, count(distinct order_id) as n')->groupBy('product_id')
            ->havingRaw('count(distinct order_id) >= 2')->orderByDesc('n')->limit(6)->pluck('product_id');

        return Product::active()->whereIn('id', $ids)->with(['images', 'variants'])->get()
            ->sortBy(fn ($p) => $ids->search($p->id))->take(3)->values();
    }

    /** "Add this and your order ships free" — from what's already in the cart plus this product's price. */
    private static function shipNudge(Product $product, Cart $cart): ?array
    {
        $free = (float) setting('free_shipping_over');
        if ($free <= 0) {
            return null;
        }
        $inCart = $cart->subtotal($cart->lines());
        $price = $product->currentPrice();
        if ($inCart >= $free) {
            return ['ok' => true, 'text' => 'Your order already ships free 🎉'];
        }
        if ($inCart + $price >= $free) {
            return ['ok' => true, 'text' => $inCart > 0 ? 'Add this and your whole order ships free 🎉' : 'This ships free — orders over '.money($free).' get free delivery'];
        }

        return ['ok' => false, 'text' => ($inCart > 0 ? 'With this in your cart, add ' : 'Add ').money($free - $inCart - $price).' more for free delivery (orders over '.money($free).')'];
    }

    /** "Check delivery" box on the product page and the PIN field at checkout. */
    public function deliveryCheck(Request $request, Delhivery $delhivery)
    {
        $pin = preg_replace('/\D/', '', (string) $request->query('pin'));
        if (! preg_match('/^[1-9]\d{5}$/', $pin)) {
            return response()->json(['ok' => false, 'message' => 'Please enter a valid 6-digit PIN code.'], 422);
        }
        $days = max(1, (int) $request->query('days', 3));
        $to = now()->addWeekdays($days)->addDays(6)->format('D, j M');
        $res = $delhivery->pincode($pin);
        $codOn = (bool) setting('cod_enabled');
        if ($res === null) {   // not configured or Delhivery unreachable: give the usual estimate
            return response()->json(['ok' => true, 'pin' => $pin, 'cod' => $codOn, 'message' => "Delivery by {$to}"]);
        }
        if (! $res['serviceable']) {
            return response()->json(['ok' => false, 'pin' => $pin, 'cod' => false, 'message' => "Sorry, we don't deliver to {$pin} yet."]);
        }

        return response()->json([
            'ok' => true, 'pin' => $pin, 'cod' => $codOn && $res['cod'], 'city' => $res['city'],
            'message' => "Delivery to {$pin}".($res['city'] ? " ({$res['city']})" : '')." by {$to}"
                .($codOn ? ($res['cod'] ? ' · Cash on delivery available' : ' · Prepaid only') : ''),
        ]);
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

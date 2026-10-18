<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Services\Analytics;
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
            'image' => $v->image ? media_url($v->image) : null,
            'sku' => $v->sku,
        ])->values();

        return view('product', compact('product', 'related', 'variants'));
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

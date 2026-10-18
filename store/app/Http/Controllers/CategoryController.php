<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\Analytics;
use App\Services\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function show(Request $request, string $path, Seo $seo, Analytics $analytics)
    {
        $category = Category::active()->where('path', trim($path, '/'))->first();
        if (! $category) {
            // WooCommerce also answers /product-category/child/ for a nested category; send it to the full path.
            $category = Category::active()->where('slug', basename($path))->firstOrFail();

            return redirect($category->url(), 301);
        }
        $category->load(['children' => fn ($q) => $q->active()->withCount('products')]);

        $products = $this->filtered($request, Product::active()->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $category->descendantIds())));
        $seo->forCategory($category, $products);
        $this->pagination($seo, $products, $category->url());
        $analytics->viewItemList($category->name, $products->items());

        return view('category', compact('category', 'products'));
    }

    public function showPaged(string $path, int $page)
    {
        return redirect(url('product-category/'.trim($path, '/')).'/'.($page > 1 ? '?page='.$page : ''), 301);
    }

    public function shop(Request $request, Seo $seo, Analytics $analytics)
    {
        $products = $this->filtered($request, Product::active());
        $seo->title('Shop All Personalised Gifts')->description('Browse every personalised gift at '.config('store.name').' - custom photo frames, mugs, cushions, lamps and more, made with your photos.')->canonical(url('shop').'/');
        $seo->breadcrumbs([['Home', url('/')], ['Shop', url('shop').'/']]);
        $this->pagination($seo, $products, url('shop').'/');
        $analytics->viewItemList('Shop', $products->items());

        return view('category', ['category' => null, 'products' => $products]);
    }

    public function shopPaged(int $page)
    {
        return redirect(url('shop').'/'.($page > 1 ? '?page='.$page : ''), 301);
    }

    public function all(Seo $seo)
    {
        $seo->title('All Categories')->canonical(url('categories'));
        $categories = Category::active()->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->active()->withCount('products')])->withCount('products')
            ->orderBy('position')->orderBy('name')->get();

        return view('categories', compact('categories'));
    }

    private function filtered(Request $request, Builder $q)
    {
        $q->with(['images', 'variants', 'primaryCategory', 'customizationFields']);
        if ($request->filled('min')) {
            $q->whereRaw('coalesce(nullif(sale_price, 0), price) >= ?', [(float) $request->min]);
        }
        if ($request->filled('max')) {
            $q->whereRaw('coalesce(nullif(sale_price, 0), price) <= ?', [(float) $request->max]);
        }
        if ($request->boolean('sale')) {
            $q->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price')->where('sale_price', '>', 0);
        }
        match ($request->query('orderby', $request->query('sort'))) {
            'price' => $q->orderByRaw('coalesce(nullif(sale_price, 0), price) asc'),
            'price-desc' => $q->orderByRaw('coalesce(nullif(sale_price, 0), price) desc'),
            'date' => $q->latest(),
            'rating' => $q->orderByDesc('rating_avg'),
            default => $q->orderByDesc('is_featured')->orderByDesc('sales_count')->latest(),
        };

        return $q->paginate(24)->withQueryString();
    }

    private function pagination(Seo $seo, $products, string $base): void
    {
        if ($products->currentPage() > 1) {
            $seo->canonical($base.'?page='.$products->currentPage());
            $seo->title($seo->title.' - Page '.$products->currentPage());
            $seo->prev = $products->currentPage() === 2 ? $base : $base.'?page='.($products->currentPage() - 1);
        }
        if ($products->hasMorePages()) {
            $seo->next = $base.'?page='.($products->currentPage() + 1);
        }
        // Filtered/sorted listings are near-duplicates; keep them out of the index.
        if (request()->hasAny(['min', 'max', 'sale', 'orderby', 'sort'])) {
            $seo->noindex();
            $seo->canonical($base);
        }
    }
}

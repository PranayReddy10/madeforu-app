<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Services\Seo;
use App\Support\HomeLayout;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    private const WITH = ['images', 'variants', 'primaryCategory', 'customizationFields'];

    public function index(Seo $seo)
    {
        $seo->title(setting('home_title') ?: config('store.name').' - Personalised Gifts Online India | Custom Photo Gifts')
            ->description(setting('home_description') ?: 'Personalised photo gifts made for you: custom frames, mugs, cushions, keychains and more. Upload your photos, we make it and deliver across India.')
            ->canonical(url('/'));
        foreach ($seo->siteSchema() as $s) {
            $seo->addSchema($s);
        }

        // Shared by several sections (the slider's built-in slides, Instagram's fallback photos).
        $bestsellers = Product::active()->with(self::WITH)->orderByDesc('sales_count')->orderByDesc('views')->take(12)->get();
        $featured = Product::active()->with(self::WITH)->where('is_featured', true)->latest()->take(12)->get();
        $approved = Review::where('is_approved', true);
        $rating = ['avg' => round((float) (clone $approved)->avg('rating'), 1), 'count' => (clone $approved)->count()];
        $shownIds = Category::withShopProductsIds();
        $biggest = null;
        $usedCategories = [];

        $sections = [];
        foreach (HomeLayout::get() as $s) {
            if (! $s['on']) {
                continue;
            }
            $s['data'] = match ($s['type']) {
                'slider' => ['banners' => Banner::where('is_active', true)->orderBy('position')->get()],
                'categories' => ['categories' => Category::active()->where('show_on_home', true)->whereNull('parent_id')->whereIn('id', $shownIds)->withShopCount()->orderBy('position')->orderBy('name')->get()],
                'products' => ['products' => $this->products($s, $bestsellers, $featured), 'more' => $this->moreLink($s)],
                'price_tiles' => ['tiles' => $this->priceTiles($s['amounts'])],
                'offers' => ['products' => Product::active()->with(self::WITH)->whereNotNull('sale_price')->where('sale_price', '>', 0)->whereColumn('sale_price', '<', 'price')
                    ->orderByRaw('(price - sale_price) / price desc')->take(10)->get()],
                'reviews' => ['reviews' => (clone $approved)->where('rating', '>=', 4)->whereNotNull('body')->with('product')->latest()->take(8)->get()],
                'category_row' => $this->categoryRow($s, $shownIds, $biggest, $usedCategories),
                default => [],
            };
            if ($s['type'] === 'faq' && $s['items']) {
                $seo->addSchema([
                    '@context' => 'https://schema.org', '@type' => 'FAQPage',
                    'mainEntity' => collect($s['items'])->map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]])->all(),
                ]);
            }
            $sections[] = $s;
        }

        return view('home', compact('sections', 'bestsellers', 'featured', 'rating'));
    }

    private function products(array $s, $bestsellers, $featured)
    {
        $q = Product::active()->with(self::WITH);

        return match ($s['source']) {
            'bestsellers' => $bestsellers->take($s['limit']),
            'featured' => $featured->take($s['limit']),
            'newest' => $q->latest()->take($s['limit'])->get(),
            'sale' => $q->whereNotNull('sale_price')->where('sale_price', '>', 0)->whereColumn('sale_price', '<', 'price')->latest()->take($s['limit'])->get(),
            'category' => $s['category_id'] ? $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', Category::find($s['category_id'])?->descendantIds() ?? [0]))
                ->orderByDesc('sales_count')->take($s['limit'])->get() : collect(),
            'manual' => $q->whereIn('id', $s['product_ids'])->get()->sortBy(fn ($p) => array_search($p->id, $s['product_ids']))->values(),
        };
    }

    private function moreLink(array $s): string
    {
        return match ($s['source']) {
            'newest' => url('shop').'/?orderby=date',
            'sale' => url('shop').'/?sale=1',
            'category' => Category::find($s['category_id'])?->url() ?? url('shop').'/',
            default => url('shop').'/',
        };
    }

    private function priceTiles(string $amounts): array
    {
        $list = collect(explode(',', $amounts))->map(fn ($a) => (int) preg_replace('/\D/', '', $a))->filter()->unique()->take(6)->values();

        return Cache::remember('home.price-tiles.'.$list->implode('-'), 600, fn () => $list
            ->map(fn ($max) => ['max' => $max, 'count' => Product::active()->whereRaw('coalesce(nullif(sale_price, 0), price) <= ?', [$max])->count()])
            ->filter(fn ($t) => $t['count'] > 0)->values()->all());
    }

    /** A chosen category, or the Nth biggest one (skipping categories already shown in other spotlights). */
    private function categoryRow(array $s, array $shownIds, &$biggest, array &$used): array
    {
        $cat = $s['category_id'] ? Category::active()->withShopCount()->find($s['category_id']) : null;
        if (! $cat) {
            $biggest ??= Category::active()->whereIn('id', $shownIds)->withShopCount()->orderByDesc('products_count')->take(12)->get();
            $cat = $biggest->reject(fn ($c) => in_array($c->id, $used, true))->values()->get(0);
        }
        if (! $cat || $cat->products_count < 2) {
            return ['category' => null, 'products' => collect()];
        }
        $used[] = $cat->id;

        return ['n' => count($used), 'category' => $cat, 'products' => $cat->products()->active()->with(self::WITH)->orderByDesc('sales_count')->orderByDesc('views')->take(8)->get()];
    }
}

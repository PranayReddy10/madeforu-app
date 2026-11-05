<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CustomizationField;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Parcel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/products, /api/v1/products/{slug|id|sku}, /api/v1/categories — active products only, never costs. */
class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        return self::json([
            'name' => config('store.name').' product API',
            'version' => 1,
            'currency' => 'INR',
            'endpoints' => [
                'products' => route('api.products').'?page=1&per_page=50',
                'product' => route('api.product', 'PRODUCT-SLUG'),
                'categories' => route('api.categories'),
            ],
            'product_filters' => [
                'category' => 'category slug, includes its sub-categories',
                'q' => 'search words',
                'in_stock' => '1 = only in-stock products',
                'featured' => '1 = only featured products',
                'updated_since' => 'date/time, e.g. 2026-10-01 — only products changed since then (for syncing)',
                'per_page' => '1–100, default 50',
                'source' => 'your app name, e.g. myapp — buy_url links then carry utm_source=myapp so orders show which app they came from',
                'page' => 'page number',
            ],
            'auth' => setting('api.key') ? 'API key required: ?key=… or X-Api-Key header' : 'open',
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1',
            'category' => 'nullable|string|max:191', 'q' => 'nullable|string|max:100',
            'updated_since' => 'nullable|date', 'in_stock' => 'nullable|boolean', 'featured' => 'nullable|boolean', 'source' => 'nullable|string|max:40',
        ]);
        $q = Product::active()->with(['images', 'variants', 'categories', 'customizationFields', 'productAttributes', 'primaryCategory'])->orderBy('id');
        if ($slug = $request->query('category')) {
            $cat = Category::where('slug', $slug)->first();
            $ids = $cat ? Category::where('id', $cat->id)->orWhere('path', 'like', $cat->path.'/%')->pluck('id') : collect([0]);
            $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $ids));
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->search($term);
        }
        if ($request->boolean('in_stock')) {
            $q->where('in_stock', true);
        }
        if ($request->boolean('featured')) {
            $q->where('is_featured', true);
        }
        if ($since = $request->query('updated_since')) {
            $q->where('updated_at', '>=', \Illuminate\Support\Carbon::parse($since));
        }
        $page = $q->paginate((int) $request->query('per_page', 50))->withQueryString();

        return self::json([
            'data' => $page->getCollection()->map(fn (Product $p) => self::product_($p))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ]);
    }

    public function product(string $product): JsonResponse
    {
        $p = Product::active()->with(['images', 'variants', 'categories', 'customizationFields', 'productAttributes', 'primaryCategory'])
            ->where(fn ($q) => $q->where('slug', $product)->orWhere('sku', $product)->when(ctype_digit($product), fn ($q) => $q->orWhere('id', (int) $product)))
            ->first();
        if (! $p) {
            $v = ProductVariant::where('sku', $product)->first();
            $p = $v ? Product::active()->whereKey($v->product_id)->first() : null;
        }

        return $p ? self::json(['data' => self::product_($p)]) : self::json(['error' => 'Product not found.'], 404);
    }

    public function categories(): JsonResponse
    {
        $cats = Category::where('is_active', true)->orderBy('path')->get();
        // Products in the category or any sub-category, each counted once.
        $pairs = \Illuminate\Support\Facades\DB::table('category_product')->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('products.is_active', true)->get(['category_id', 'product_id']);
        $paths = Category::pluck('path', 'id');
        $counts = $cats->mapWithKeys(fn ($c) => [$c->id => $pairs->filter(function ($r) use ($paths, $c) {
            $p = (string) ($paths[$r->category_id] ?? '');

            return $p === $c->path || str_starts_with($p, $c->path.'/');
        })->pluck('product_id')->unique()->count()]);

        return self::json(['data' => $cats->map(fn (Category $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'parent_id' => $c->parent_id,
            'url' => $c->url(),
            'image' => $c->image ? media_url($c->image) : null,
            'description' => $c->description,
            'products' => (int) ($counts[$c->id] ?? 0),
            'products_api' => route('api.products', ['category' => $c->slug]),
        ])->values()]);
    }

    /**
     * Product page link for a "Buy" button in another app: ?source=myapp on the API call tags it
     * utm_source=myapp&utm_medium=app, so the order shows which app it came from (Orders → order → Source).
     */
    private static function buyUrl(string $url): string
    {
        $source = substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string) request()->query('source', 'app'))), 0, 40) ?: 'app';

        return $url.(str_contains($url, '?') ? '&' : '?').'utm_source='.$source.'&utm_medium=app';
    }

    /** Readable JSON: real slashes in URLs and ₹ / – as themselves. */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function product_(Product $p): array
    {
        $price = $p->currentPrice();
        $regular = $p->regularPrice();

        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'slug' => $p->slug,
            'url' => $p->url(),
            'buy_url' => self::buyUrl($p->url()),
            'type' => $p->type,
            'price' => $price,
            'regular_price' => $regular,
            'max_price' => $p->maxPrice(),
            'on_sale' => $price < $regular,
            'discount_percent' => $p->discountPercent(),
            'currency' => 'INR',
            'in_stock' => (bool) $p->in_stock,
            'stock_qty' => $p->stock_qty,
            'short_description' => $p->short_description,
            'description_html' => $p->description,
            'description_text' => str(strip_tags((string) $p->description))->squish()->toString(),
            'image' => $p->mainImageUrl(),
            'thumbnail' => $p->thumbUrl(),
            'images' => $p->images->map(fn ($i) => ['url' => $i->url(), 'alt' => $i->alt ?: $p->name])->values(),
            'category' => $p->primaryCategory?->name,
            'categories' => $p->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'url' => $c->url()])->values(),
            'attributes' => $p->productAttributes->mapWithKeys(fn ($a) => [$a->name => $a->values]),
            'variants' => $p->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'options' => $v->options,
                'price' => $v->currentPrice(),
                'regular_price' => (float) $v->price,
                'in_stock' => (bool) $v->in_stock,
                'stock_qty' => $v->stock_qty,
                'image' => $v->image ? media_url($v->image) : null,
                'url' => $p->url().'?variant='.$v->id,
                'buy_url' => self::buyUrl($p->url().'?variant='.$v->id),
            ])->values(),
            'personalisation' => $p->customizationFields->map(fn (CustomizationField $f) => array_filter([
                'label' => $f->label,
                'type' => $f->type,
                'required' => (bool) $f->required,
                'help' => $f->help,
                'extra_price' => (float) $f->extra_price ?: null,
                'max_files' => $f->type === 'images' ? $f->max_files : null,
                'max_length' => $f->max_length,
                'options' => in_array($f->type, ['select', 'choice'], true)
                    ? collect($f->options)->map(fn ($o) => array_filter(['label' => $o['label'] ?? null, 'extra_price' => (float) ($o['price'] ?? 0) ?: null, 'photos' => $o['photos'] ?? null]))->values()->all()
                    : null,
            ], fn ($v) => $v !== null))->values(),
            'production_days' => (int) $p->production_days,
            'weight_g' => $p->weight_kg ? (int) round(Parcel::itemGrams($p)) : null,
            'dimensions_cm' => $p->length_cm ? ['length' => (float) $p->length_cm, 'width' => (float) $p->width_cm, 'height' => (float) $p->height_cm] : null,
            'brand' => $p->brand ?: config('store.name'),
            'gtin' => $p->gtin,
            'hsn_code' => $p->hsn_code,
            'gst_rate' => $p->gst_rate !== null ? (float) $p->gst_rate : null,
            'rating' => ['average' => $p->rating_avg !== null ? round((float) $p->rating_avg, 1) : null, 'count' => (int) $p->rating_count],
            'featured' => (bool) $p->is_featured,
            'seo' => ['title' => $p->seoTitle(), 'description' => $p->seoDescription(), 'keywords' => $p->meta_keywords],
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}

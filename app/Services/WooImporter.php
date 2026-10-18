<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\CustomerUpload;
use App\Models\CustomizationField;
use App\Models\Media;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Redirect;
use App\Models\Review;
use App\Models\User;
use App\Support\DefaultPages;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies the catalogue from the live WooCommerce site through its public Store API
 * (/wp-json/wc/store/v1): categories, products, prices, sale prices, variations, images,
 * reviews and pages. Slugs and category paths are kept as they are, so every
 * /product/{slug}/ and /product-category/{path}/ URL stays the same.
 *
 * Safe to run again: everything is matched on the WooCommerce id and updated in place.
 */
class WooImporter
{
    private string $base;

    /** @var array<int, int> woo category id => local id */
    private array $catMap = [];

    public bool $downloadImages = true;

    public bool $addUploadFields = true;

    public ?Closure $log = null;

    public array $stats = ['categories' => 0, 'products' => 0, 'variants' => 0, 'images' => 0, 'reviews' => 0, 'pages' => 0, 'redirects' => 0];

    /** Responses fetched ahead of time (WooCommerce REST API mode), keyed by Store API path. */
    private array $prefetched = [];

    private ?string $key;

    private ?string $secret;

    public function __construct(?string $base = null, ?string $key = null, ?string $secret = null)
    {
        $this->base = rtrim($base ?: config('store.woo.url'), '/');
        $this->key = $key ?: config('store.woo.consumer_key');
        $this->secret = $secret ?: config('store.woo.consumer_secret');
    }

    /**
     * With WooCommerce REST API keys the importer reads /wc/v3, which also returns draft and private
     * products (imported hidden). Without keys it reads the public Store API: published products only.
     */
    public function usesApiKeys(): bool
    {
        return (bool) ($this->key && $this->secret);
    }

    private function say(string $msg): void
    {
        if ($this->log) {
            ($this->log)($msg);
        }
    }

    /** GET every page of a WordPress REST collection. */
    private function all(string $path, array $query = []): array
    {
        $out = [];
        for ($page = 1; $page < 200; $page++) {
            $res = $this->request($path)->get($this->base.$path, $query + ['per_page' => 100, 'page' => $page]);
            if ($res->status() === 400 && $page > 1) {
                break; // past the last page
            }
            if (! $res->successful()) {
                if ($page === 1) {
                    throw new \RuntimeException("GET {$path} failed: HTTP {$res->status()} ".Str::limit($res->body(), 300));
                }
                break;
            }
            $items = $res->json();
            if (! is_array($items) || $items === []) {
                break;
            }
            array_push($out, ...$items);
            $totalPages = (int) $res->header('X-WP-TotalPages');
            if (($totalPages && $page >= $totalPages) || count($items) < 100) {
                break;
            }
        }

        return $out;
    }

    private function request(string $path): PendingRequest
    {
        $http = Http::timeout(60)->retry(3, 2000, throw: false)
            ->withHeaders(['User-Agent' => 'MadeForU-Migrator/1.0'])
            ->acceptJson();
        if (str_starts_with($path, '/wp-json/wc/v3/') && $this->usesApiKeys()) {
            // Basic auth over HTTPS; the query-string form also works on servers that drop the Authorization header.
            $http = $http->withBasicAuth($this->key, $this->secret)
                ->withQueryParameters(['consumer_key' => $this->key, 'consumer_secret' => $this->secret]);
        }

        return $http;
    }

    private function one(string $path): ?array
    {
        if (array_key_exists($path, $this->prefetched)) {
            return $this->prefetched[$path];
        }
        $res = $this->request($path)->get($this->base.$path);

        return $res->successful() ? $res->json() : null;
    }

    public function run(bool $withPages = true, bool $withReviews = true): array
    {
        $this->importCategories();
        $this->importProducts();
        if ($withReviews) {
            $this->importReviews();
        }
        if ($withPages) {
            $this->importPages();
            DefaultPages::apply();   // About, Contact and policies get clean, designed versions
        }

        return $this->stats;
    }

    public function importCategories(): void
    {
        $cats = $this->usesApiKeys()
            ? array_map(fn ($c) => $c + ['permalink' => null], $this->all('/wp-json/wc/v3/products/categories'))
            : $this->all('/wp-json/wc/store/v1/products/categories');
        $this->say('Categories: '.count($cats));
        // Parents before children so the path can be built from the parent.
        $byId = collect($cats)->keyBy('id');
        $depth = function ($c) use ($byId, &$depth) {
            return $c['parent'] && $byId->has($c['parent']) ? 1 + $depth($byId[$c['parent']]) : 0;
        };
        foreach (collect($cats)->sortBy($depth) as $i => $c) {
            if (($c['slug'] ?? '') === 'uncategorized' && ($c['count'] ?? 0) == 0) {
                continue;
            }
            $cat = Category::firstOrNew(['woo_id' => $c['id']]);
            $cat->fill([
                'name' => html_entity_decode($c['name'], ENT_QUOTES),
                'slug' => rawurldecode($c['slug']),
                'parent_id' => $c['parent'] ? ($this->catMap[$c['parent']] ?? null) : null,
                'description' => $c['description'] ?: $cat->description,
                'position' => $cat->exists ? $cat->position : $i,
            ]);
            if (! empty($c['image']['src'])) {
                $cat->image = $this->image($c['image']['src'], 'categories/'.$cat->slug);
                Media::register($cat->image, 'categories', $cat->name);
            }
            $cat->save();
            $this->catMap[$c['id']] = $cat->id;
            $this->keepOldUrl($c['permalink'] ?? $c['link'] ?? null, $cat->url());
            $this->stats['categories']++;
        }
    }

    public function importProducts(): void
    {
        if (! $this->catMap) {
            $this->catMap = Category::whereNotNull('woo_id')->pluck('id', 'woo_id')->all();
        }
        if ($this->usesApiKeys()) {
            $v3 = $this->all('/wp-json/wc/v3/products', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc']);
            $v3 = array_values(array_filter($v3, fn ($p) => ! in_array($p['status'] ?? '', ['trash', 'auto-draft'], true)));
            $products = array_map(fn ($p) => $this->fromV3($p), $v3);
            $hidden = count(array_filter($products, fn ($p) => ! $p['_active']));
            $this->say('Products: '.count($products).($hidden ? " ({$hidden} drafts/private, imported hidden)" : ''));
            $seo = collect($v3)->mapWithKeys(fn ($p) => [$p['id'] => $this->yoastFromMeta($p['meta_data'] ?? [])])->filter()->all();
        } else {
            $products = $this->all('/wp-json/wc/store/v1/products', ['orderby' => 'id', 'order' => 'asc']);
            $this->say('Products: '.count($products));
            $seo = $this->yoastMeta();
        }

        foreach ($products as $w) {
            $minor = (int) ($w['prices']['currency_minor_unit'] ?? 2);
            $amount = fn ($v) => $v === null || $v === '' ? null : round(((int) $v) / (10 ** $minor), 2);
            $regular = $amount($w['prices']['regular_price'] ?? null) ?? 0;
            $sale = $amount($w['prices']['sale_price'] ?? null);
            $type = ($w['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple';

            $p = Product::firstOrNew(['woo_id' => $w['id']]);
            $meta = $seo[$w['id']] ?? [];
            $name = html_entity_decode($w['name'], ENT_QUOTES);
            $p->fill([
                'name' => $name,
                'slug' => $this->uniqueSlug(rawurldecode((string) $w['slug']) ?: Str::slug($name) ?: 'product-'.$w['id'], $p->id),
                'sku' => $w['sku'] ?: null,
                'type' => $type,
                'price' => $regular,
                'sale_price' => $sale !== null && $sale > 0 && $sale < $regular ? $sale : null,
                'short_description' => $w['short_description'] ?? null,
                'description' => $w['description'] ?? null,
                'in_stock' => (bool) ($w['is_in_stock'] ?? true),
                'is_active' => $w['_active'] ?? true,
                'primary_category_id' => isset($w['categories'][0]) ? ($this->catMap[$w['categories'][0]['id']] ?? null) : null,
                'rating_avg' => (float) ($w['average_rating'] ?? 0),
                'rating_count' => (int) ($w['review_count'] ?? 0),
                'meta_title' => $p->meta_title ?: ($meta['title'] ?? null),
                'meta_description' => $p->meta_description ?: ($meta['description'] ?? null),
                'meta_keywords' => $p->meta_keywords ?: (collect($w['tags'] ?? [])->pluck('name')->implode(', ') ?: null),
            ]);
            $p->save();
            $p->categories()->sync(collect($w['categories'] ?? [])->map(fn ($c) => $this->catMap[$c['id']] ?? null)->filter()->values()->all());
            $this->keepOldUrl($w['permalink'] ?? null, $p->url());

            // Images: replace the set if WooCommerce's list changed.
            $srcs = collect($w['images'] ?? [])->values();
            $have = $p->images()->pluck('source_url')->all();
            // Re-fetch when the list changed, or when an earlier run could only keep remote URLs.
            $remote = $p->images()->where('path', 'like', 'http%')->pluck('path')->contains(fn ($u) => ! $this->keepRemote($u));
            if ($srcs->pluck('src')->all() !== $have || ($remote && $this->downloadImages)) {
                $p->images()->delete();
                foreach ($srcs as $i => $img) {
                    $created = ProductImage::create([
                        'product_id' => $p->id,
                        'path' => $this->image($img['src'], 'products/'.$p->slug),
                        'source_url' => $img['src'],
                        'alt' => $img['alt'] ?: $p->name.($i ? ' - '.($i + 1) : ''),
                        'position' => $i,
                    ]);
                    Media::register($created->path, 'products', $created->alt);
                    $this->stats['images']++;
                }
            }

            // Attributes and variations
            $p->productAttributes()->delete();
            $termNames = [];
            foreach (array_values($w['attributes'] ?? []) as $i => $a) {
                $values = collect($a['terms'] ?? [])->pluck('name')->map(fn ($n) => html_entity_decode($n, ENT_QUOTES))->all();
                foreach ($a['terms'] ?? [] as $t) {
                    $termNames[$a['name']][$t['slug']] = html_entity_decode($t['name'], ENT_QUOTES);
                }
                $p->productAttributes()->create([
                    'name' => html_entity_decode($a['name'], ENT_QUOTES), 'values' => $values,
                    'is_variation' => (bool) ($a['has_variations'] ?? false), 'position' => $i,
                ]);
            }
            if ($type === 'variable') {
                $this->importVariations($p, $w, $termNames, $amount);
            }

            if ($this->addUploadFields && $p->customizationFields()->doesntExist()) {
                $this->defaultCustomization($p);
            }
            $this->stats['products']++;
            $this->say('  ✓ '.$p->name);
        }
    }

    private function importVariations(Product $p, array $w, array $termNames, Closure $amount): void
    {
        $keep = [];
        foreach (array_values($w['variations'] ?? []) as $i => $v) {
            $detail = $this->one('/wp-json/wc/store/v1/products/'.$v['id']);
            if ($detail && (($detail['is_purchasable'] ?? true) === false || (int) ($detail['prices']['price'] ?? 0) === 0)) {
                continue; // WooCommerce does not sell it either (e.g. a stray empty variation)
            }
            $options = [];
            foreach ($v['attributes'] ?? [] as $a) {
                $name = html_entity_decode($a['name'], ENT_QUOTES);
                // Store API gives term slugs for global attributes; show the term's name.
                $options[$name] = $termNames[$a['name']][$a['value']] ?? html_entity_decode((string) $a['value'], ENT_QUOTES);
            }
            $reg = $detail ? $amount($detail['prices']['regular_price'] ?? null) : null;
            $sale = $detail ? $amount($detail['prices']['sale_price'] ?? null) : null;
            $variant = ProductVariant::updateOrCreate(['woo_id' => $v['id']], [
                'product_id' => $p->id,
                'options' => $options,
                'sku' => $detail['sku'] ?? null ?: null,
                'price' => $reg ?? (float) $p->price,
                'sale_price' => $sale && $reg && $sale < $reg ? $sale : null,
                'in_stock' => (bool) ($detail['is_in_stock'] ?? true),
                'image' => ! empty($detail['images'][0]['src']) ? $this->image($detail['images'][0]['src'], 'products/'.$p->slug) : null,
                'position' => $i,
            ]);
            $keep[] = $variant->id;
            if ($variant->image) {
                Media::register($variant->image, 'products');
            }
            $this->stats['variants']++;
        }
        $keep ? $p->variants()->whereNotIn('id', $keep)->delete() : $p->variants()->delete();
        // The parent's own price on a variable product is the cheapest variation.
        if ($keep) {
            $cheapest = $p->variants()->get()->sortBy(fn ($v) => $v->currentPrice())->first();
            $p->update(['price' => $cheapest->price, 'sale_price' => $cheapest->sale_price]);
        }
    }

    /**
     * Every product here is personalised, but WooCommerce upload plugins keep their fields in their own
     * tables that the Store API does not expose. Start each product with a photo upload sized from its
     * name ("Collage of 6 photos" -> 6) and an optional message; adjust per product in admin.
     */
    public function defaultCustomization(Product $p): void
    {
        $n = 1;
        if (preg_match('/(\d{1,2})\s*(photos?|pics?|pictures?|images?)/i', $p->name.' '.strip_tags((string) $p->short_description), $m)) {
            $n = max(1, min(30, (int) $m[1]));
        }
        CustomizationField::create([
            'product_id' => $p->id, 'type' => 'images', 'label' => $n > 1 ? "Upload your {$n} photos" : 'Upload your photo',
            'help' => 'Clear, well-lit photos print best. JPG, PNG or HEIC, up to '.round(config('store.upload_max_kb') / 1024).' MB each.',
            'required' => true, 'min_files' => $n, 'max_files' => $n, 'position' => 0,
        ]);
        CustomizationField::create([
            'product_id' => $p->id, 'type' => 'textarea', 'label' => 'Name / message to print',
            'help' => 'Leave blank if you do not want any text.', 'required' => false, 'max_length' => 200, 'position' => 1,
        ]);
    }

    public function importReviews(): void
    {
        $products = Product::whereNotNull('woo_id')->pluck('id', 'woo_id');
        try {
            $reviews = $this->usesApiKeys()
                ? $this->all('/wp-json/wc/v3/products/reviews', ['status' => 'approved'])
                : $this->all('/wp-json/wc/store/v1/products/reviews');
        } catch (\Throwable $e) {
            $this->say('Reviews skipped: '.$e->getMessage());

            return;
        }
        foreach ($reviews as $r) {
            $pid = $products[$r['product_id']] ?? null;
            if (! $pid) {
                continue;
            }
            Review::updateOrCreate(
                ['product_id' => $pid, 'name' => $r['reviewer'], 'created_at' => date('Y-m-d H:i:s', strtotime($r['date_created']))],
                ['rating' => max(1, (int) $r['rating']), 'body' => trim(strip_tags(html_entity_decode($r['review'] ?? '', ENT_QUOTES))), 'is_approved' => true],
            );
            $this->stats['reviews']++;
        }
    }

    public function importPages(): void
    {
        try {
            $pages = $this->all('/wp-json/wp/v2/pages', ['status' => 'publish']);
        } catch (\Throwable $e) {
            $this->say('Pages skipped: '.$e->getMessage());

            return;
        }
        // WooCommerce/theme template pages: this store has its own version, so send the old URL there instead.
        $replaced = [
            'cart' => '/cart', 'shop-cart' => '/cart', 'checkout' => '/checkout', 'shop-checkout' => '/checkout',
            'my-account' => '/my-account', 'shop' => '/shop/', 'wishlist' => '/wishlist', 'tracking-order' => '/track-order',
            'order-tracking' => '/track-order', 'categories' => '/categories', 'compare' => '/shop/', 'deals' => '/shop/?sale=1',
            'flash-deals' => '/shop/?sale=1', 'product-deals-of-the-day' => '/shop/?sale=1', 'products-recently-viewed' => '/shop/',
            'home' => '/', 'sample-page' => '/',
        ];
        foreach ($pages as $wp) {
            // Keep the WordPress path, nested pages included: /legal/shipping-delivery-policy/
            $path = trim(rawurldecode((string) parse_url($wp['link'] ?? '', PHP_URL_PATH)), '/') ?: $wp['slug'];
            if (isset($replaced[$wp['slug']])) {
                if ('/'.$path !== $replaced[$wp['slug']] && '/'.$path.'/' !== $replaced[$wp['slug']]) {
                    Redirect::updateOrCreate(['from_path' => Redirect::normalise('/'.$path)], ['to_path' => $replaced[$wp['slug']], 'status' => 301]);
                    $this->stats['redirects']++;
                }

                continue;
            }
            $page = Page::firstOrNew(['slug' => $path]);
            $page->fill([
                'title' => html_entity_decode(strip_tags($wp['title']['rendered'] ?? $wp['slug']), ENT_QUOTES),
                'body' => $page->exists && $page->body ? $page->body : $this->cleanHtml($wp['content']['rendered'] ?? ''),
                'meta_title' => $wp['yoast_head_json']['title'] ?? $page->meta_title,
                'meta_description' => $wp['yoast_head_json']['description'] ?? $page->meta_description,
                'is_active' => true,
            ])->save();
            $this->stats['pages']++;
        }
    }

    /**
     * Turn a /wc/v3 product into the Store API shape the rest of the importer reads, and prefetch its
     * variations so importVariations() finds them without another request each.
     */
    private function fromV3(array $p): array
    {
        $minor = fn ($v) => $v === '' || $v === null ? null : (string) (int) round(((float) $v) * 100);
        $variations = [];
        if (($p['type'] ?? '') === 'variable') {
            foreach ($this->all('/wp-json/wc/v3/products/'.$p['id'].'/variations') as $v) {
                $variations[] = ['id' => $v['id'], 'attributes' => array_map(
                    fn ($a) => ['name' => $a['name'], 'value' => Str::slug((string) $a['option'])], $v['attributes'] ?? [])];
                $this->prefetched['/wp-json/wc/store/v1/products/'.$v['id']] = [
                    // A private variation is switched off in WooCommerce; one without a price can't be bought.
                    'is_purchasable' => ($v['status'] ?? 'publish') !== 'private' && ($v['price'] ?? '') !== '',
                    'prices' => ['price' => $minor($v['price'] ?? null) ?? '0', 'regular_price' => $minor($v['regular_price'] ?? null),
                        'sale_price' => $minor($v['sale_price'] ?? null), 'currency_minor_unit' => 2],
                    'sku' => $v['sku'] ?? null,
                    'is_in_stock' => ($v['stock_status'] ?? 'instock') !== 'outofstock',
                    'images' => ! empty($v['image']['src']) ? [['src' => $v['image']['src']]] : [],
                ];
            }
        }

        return [
            'id' => $p['id'],
            'name' => $p['name'] ?: 'Untitled product '.$p['id'],
            'slug' => $p['slug'] ?? '',
            'type' => $p['type'] ?? 'simple',
            'sku' => $p['sku'] ?? '',
            'permalink' => ($p['status'] ?? '') === 'publish' ? ($p['permalink'] ?? null) : null,
            'short_description' => $p['short_description'] ?? null,
            'description' => $p['description'] ?? null,
            'prices' => ['regular_price' => $minor($p['regular_price'] ?? null) ?? $minor($p['price'] ?? null),
                'sale_price' => $minor($p['sale_price'] ?? null), 'currency_minor_unit' => 2],
            'is_in_stock' => ($p['stock_status'] ?? 'instock') !== 'outofstock',
            'average_rating' => $p['average_rating'] ?? 0,
            'review_count' => $p['rating_count'] ?? 0,
            'images' => array_map(fn ($i) => ['src' => $i['src'], 'alt' => $i['alt'] ?? ''], $p['images'] ?? []),
            'categories' => $p['categories'] ?? [],
            'tags' => $p['tags'] ?? [],
            'attributes' => array_map(fn ($a) => [
                'name' => $a['name'], 'has_variations' => (bool) ($a['variation'] ?? false),
                'terms' => array_map(fn ($o) => ['name' => $o, 'slug' => Str::slug((string) $o)], $a['options'] ?? []),
            ], $p['attributes'] ?? []),
            'variations' => $variations,
            '_active' => ($p['status'] ?? 'publish') === 'publish',
        ];
    }

    /** Yoast's own fields, skipping titles that still hold template variables like %%sitename%%. */
    private function yoastFromMeta(array $meta): array
    {
        $get = fn ($k) => collect($meta)->firstWhere('key', $k)['value'] ?? null;
        $clean = fn ($v) => is_string($v) && $v !== '' && ! str_contains($v, '%%') ? $v : null;

        return array_filter(['title' => $clean($get('_yoast_wpseo_title')), 'description' => $clean($get('_yoast_wpseo_metadesc'))]);
    }

    /** Drafts often share a name (and so a slug) with the published product they were copied from. */
    private function uniqueSlug(string $slug, ?int $ignoreId): string
    {
        $base = $slug;
        for ($n = 2; Product::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** WooCommerce's Indian state codes → the names the checkout uses. */
    private const STATES = ['AN' => 'Andaman and Nicobar Islands', 'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh', 'AS' => 'Assam',
        'BR' => 'Bihar', 'CH' => 'Chandigarh', 'CT' => 'Chhattisgarh', 'CG' => 'Chhattisgarh', 'DN' => 'Dadra and Nagar Haveli and Daman and Diu',
        'DD' => 'Dadra and Nagar Haveli and Daman and Diu', 'DL' => 'Delhi', 'GA' => 'Goa', 'GJ' => 'Gujarat', 'HR' => 'Haryana',
        'HP' => 'Himachal Pradesh', 'JK' => 'Jammu and Kashmir', 'JH' => 'Jharkhand', 'KA' => 'Karnataka', 'KL' => 'Kerala', 'LA' => 'Ladakh',
        'LD' => 'Lakshadweep', 'MP' => 'Madhya Pradesh', 'MH' => 'Maharashtra', 'MN' => 'Manipur', 'ML' => 'Meghalaya', 'MZ' => 'Mizoram',
        'NL' => 'Nagaland', 'OR' => 'Odisha', 'OD' => 'Odisha', 'PY' => 'Puducherry', 'PB' => 'Punjab', 'RJ' => 'Rajasthan', 'SK' => 'Sikkim',
        'TN' => 'Tamil Nadu', 'TS' => 'Telangana', 'TG' => 'Telangana', 'TR' => 'Tripura', 'UP' => 'Uttar Pradesh', 'UK' => 'Uttarakhand',
        'UT' => 'Uttarakhand', 'WB' => 'West Bengal'];

    /**
     * WooCommerce coupons, matched by code. Percent and fixed-cart coupons map directly; fixed-product
     * coupons become fixed amounts off the order. Needs REST API keys.
     */
    public function importCoupons(): void
    {
        if (! $this->usesApiKeys()) {
            throw new \RuntimeException('Importing coupons needs WooCommerce REST API keys (--key/--secret).');
        }
        $this->stats['coupons'] = 0;
        foreach ($this->all('/wp-json/wc/v3/coupons') as $c) {
            $code = trim((string) ($c['code'] ?? ''));
            if ($code === '' || (float) ($c['amount'] ?? 0) <= 0) {
                continue;
            }
            $coupon = Coupon::whereRaw('upper(code) = ?', [strtoupper($code)])->first() ?? new Coupon(['code' => strtoupper($code)]);
            $coupon->fill([
                'type' => ($c['discount_type'] ?? '') === 'percent' ? 'percent' : 'fixed',
                'value' => (float) $c['amount'],
                'min_subtotal' => (float) ($c['minimum_amount'] ?? 0),
                'usage_limit' => ($c['usage_limit'] ?? null) ?: null,
                'used' => max((int) ($c['usage_count'] ?? 0), (int) $coupon->used),
                'expires_at' => ! empty($c['date_expires']) ? Carbon::parse($c['date_expires']) : null,
                'is_active' => ($c['status'] ?? 'publish') === 'publish',
            ])->save();
            $this->say('  coupon '.$coupon->code);
            $this->stats['coupons']++;
        }
    }

    /**
     * Customer accounts. They get no password here: "Forgot password" on the new site lets them set one.
     * Needs REST API keys.
     */
    public function importCustomers(): void
    {
        if (! $this->usesApiKeys()) {
            throw new \RuntimeException('Importing customers needs WooCommerce REST API keys (--key/--secret).');
        }
        $customers = $this->all('/wp-json/wc/v3/customers', ['role' => 'all', 'orderby' => 'id', 'order' => 'asc']);
        $this->say('Customers: '.count($customers));
        $this->stats['customers'] = 0;
        foreach ($customers as $c) {
            $email = strtolower(trim((string) ($c['email'] ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || in_array($c['role'] ?? '', ['administrator', 'shop_manager'], true)) {
                continue;
            }
            $name = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''))
                ?: trim(($c['billing']['first_name'] ?? '').' '.($c['billing']['last_name'] ?? '')) ?: ($c['username'] ?? Str::before($email, '@'));
            $user = User::where('woo_id', $c['id'])->first() ?? User::where('email', $email)->first() ?? new User;
            $phone = $this->phone($c['billing']['phone'] ?? '');
            $user->forceFill([
                'woo_id' => $c['id'],
                'name' => $user->name ?: $name,
                'email' => $user->email ?: $email,
                'phone' => $user->phone ?: ($phone && ! User::where('phone', $phone)->whereKeyNot($user->id ?? 0)->exists() ? $phone : null),
                // Unusable until they set one with "Forgot password".
                'password' => $user->password ?: Hash::make(Str::random(40)),
                'created_at' => $user->created_at ?? (! empty($c['date_created']) ? date('Y-m-d H:i:s', strtotime($c['date_created'])) : now()),
            ])->save();
            $this->stats['customers']++;
        }
    }

    /**
     * Past orders, so customers see their history and you keep your records. No emails, WhatsApp,
     * stock or invoice numbers are triggered for them. Needs REST API keys.
     */
    public function importOrders(): void
    {
        if (! $this->usesApiKeys()) {
            throw new \RuntimeException('Importing orders needs WooCommerce REST API keys (--key/--secret).');
        }
        $orders = $this->all('/wp-json/wc/v3/orders', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc']);
        $orders = array_filter($orders, fn ($o) => ! in_array($o['status'] ?? '', ['trash', 'checkout-draft'], true));
        $this->say('Orders: '.count($orders));
        $this->stats['orders'] = 0;
        $products = Product::whereNotNull('woo_id')->pluck('id', 'woo_id');
        $variants = ProductVariant::whereNotNull('woo_id')->pluck('id', 'woo_id');
        $users = User::whereNotNull('woo_id')->pluck('id', 'woo_id');

        foreach ($orders as $w) {
            $ship = array_filter($w['shipping'] ?? []) ? $w['shipping'] : ($w['billing'] ?? []);
            $bill = $w['billing'] ?? [];
            $status = $w['status'] ?? 'pending';
            $method = ($w['payment_method'] ?? '') === 'cod' ? 'cod' : (str_contains((string) ($w['payment_method'] ?? ''), 'cashfree') ? 'cashfree' : (($w['payment_method'] ?? '') ?: 'razorpay'));
            $paid = ! empty($w['date_paid']) || ($status === 'completed' && $method === 'cod');
            $email = strtolower(trim((string) ($bill['email'] ?? '')));
            $userId = $users[$w['customer_id'] ?? 0] ?? ($email ? User::where('email', $email)->value('id') : null);
            $codFee = collect($w['fee_lines'] ?? [])->sum(fn ($f) => (float) $f['total']);
            $created = ! empty($w['date_created']) ? date('Y-m-d H:i:s', strtotime($w['date_created'])) : now();

            $order = Order::firstOrNew(['woo_id' => $w['id']]);
            $order->forceFill([
                'number' => 'WC-'.($w['number'] ?? $w['id']),
                'user_id' => $userId,
                'status' => match ($status) {
                    'processing' => 'confirmed', 'completed' => 'delivered', 'cancelled', 'refunded', 'failed' => 'cancelled', default => 'pending',
                },
                'payment_method' => $method,
                'payment_status' => $status === 'refunded' ? 'refunded' : ($status === 'failed' ? 'failed' : ($paid ? 'paid' : 'unpaid')),
                'name' => trim(($ship['first_name'] ?? '').' '.($ship['last_name'] ?? '')) ?: trim(($bill['first_name'] ?? '').' '.($bill['last_name'] ?? '')) ?: 'Customer',
                'email' => $email ?: 'no-email@madeforu.co.in',
                'phone' => $this->phone($bill['phone'] ?? ($ship['phone'] ?? '')) ?: '0000000000',
                'address_line1' => ($ship['address_1'] ?? '') ?: '-',
                'address_line2' => ($ship['address_2'] ?? '') ?: null,
                'city' => ($ship['city'] ?? '') ?: '-',
                'state' => self::STATES[strtoupper((string) ($ship['state'] ?? ''))] ?? (($ship['state'] ?? '') ?: '-'),
                'pincode' => preg_replace('/\s/', '', (string) ($ship['postcode'] ?? '')) ?: '000000',
                'notes' => ($w['customer_note'] ?? '') ?: null,
                'subtotal' => round(collect($w['line_items'] ?? [])->sum(fn ($l) => (float) $l['subtotal']), 2),
                'discount' => (float) ($w['discount_total'] ?? 0),
                'coupon_code' => collect($w['coupon_lines'] ?? [])->pluck('code')->implode(', ') ?: null,
                'shipping' => (float) ($w['shipping_total'] ?? 0),
                'cod_fee' => $codFee,
                'total' => (float) ($w['total'] ?? 0),
                'razorpay_payment_id' => $method === 'razorpay' ? (($w['transaction_id'] ?? '') ?: null) : null,
                'stock_reduced' => false,
                'delivered_at' => $status === 'completed' && ! empty($w['date_completed']) ? date('Y-m-d H:i:s', strtotime($w['date_completed'])) : null,
                // Old orders never get a review request from the new site.
                'review_requested_at' => $created,
                'created_at' => $created,
                'updated_at' => ! empty($w['date_modified']) ? date('Y-m-d H:i:s', strtotime($w['date_modified'])) : $created,
            ]);
            $order->timestamps = false;
            $order->save();

            $order->items()->delete();
            foreach ($w['line_items'] ?? [] as $li) {
                $qty = max(1, (int) $li['quantity']);
                // Customer photos: the MadeForU upload plugin keeps the real links in _mfu_cb_data.
                [$photoParts, $photoLabels] = $this->photoParts($li, (int) $w['id']);
                // What the customer chose or typed. Photo fields are replaced by the photos themselves.
                $meta = collect($li['meta_data'] ?? [])->filter(fn ($m) => ! str_starts_with((string) ($m['key'] ?? '_'), '_') && is_scalar($m['display_value'] ?? $m['value'] ?? null))
                    ->map(fn ($m) => ['label' => strip_tags((string) ($m['display_key'] ?? $m['key'])), 'type' => 'text',
                        'value' => trim(html_entity_decode(strip_tags((string) ($m['display_value'] ?? $m['value'])), ENT_QUOTES))])
                    ->filter(fn ($m) => $m['value'] !== '' && ! in_array(strtolower($m['label']), $photoLabels, true))
                    ->values()->all();
                $meta = array_merge(array_map(fn ($p) => array_diff_key($p, ['uploads' => 1]), $photoParts), $meta);
                $item = $order->items()->create([
                    'product_id' => $products[$li['product_id'] ?? 0] ?? null,
                    'variant_id' => $variants[$li['variation_id'] ?? 0] ?? null,
                    'name' => html_entity_decode((string) $li['name'], ENT_QUOTES),
                    'sku' => ($li['sku'] ?? '') ?: null,
                    'qty' => $qty,
                    'unit_price' => round((float) $li['total'] / $qty, 2),
                    'line_total' => (float) $li['total'],
                    'customization' => $meta ?: null,
                ]);
                foreach ($photoParts as $part) {
                    CustomerUpload::whereIn('id', $part['uploads'])->update(['order_item_id' => $item->id]);
                }
            }
            $this->stats['orders']++;
        }
    }

    /**
     * Download an order line's customer photos into private storage and describe them the way new
     * orders do: [{label, type: images, files: [tokens], slots: [...], uploads: [ids]}].
     *
     * @return array{0: array, 1: array<int, string>} parts, lower-cased labels of the photo fields
     */
    private function photoParts(array $li, int $wooOrderId): array
    {
        $data = collect($li['meta_data'] ?? [])->firstWhere('key', '_mfu_cb_data')['value'] ?? null;
        if (! is_array($data)) {
            return [[], []];
        }
        $byLabel = [];
        foreach ($data as $block) {
            foreach ($block['fields'] ?? [] as $f) {
                $urls = collect($f['instances'] ?? [])->pluck('photo_url')->filter()->values();
                if ($urls->isEmpty()) {
                    continue;
                }
                $label = trim((string) ($f['label'] ?? 'Photos'));
                foreach ($urls as $k => $url) {
                    $byLabel[$label][] = ['url' => $url, 'slot' => count($data) > 1 ? 'Item '.($block['item'] ?? 1).($urls->count() > 1 ? ' · photo '.($k + 1) : '') : ($urls->count() > 1 ? 'Photo '.($k + 1) : $label)];
                }
            }
        }
        $parts = [];
        foreach ($byLabel as $label => $photos) {
            $tokens = $ids = $slots = [];
            foreach ($photos as $p) {
                if ($u = $this->customerPhoto($p['url'], $wooOrderId)) {
                    $tokens[] = $u->token;
                    $ids[] = $u->id;
                    $slots[] = $p['slot'];
                }
            }
            $parts[] = ['label' => $label, 'type' => 'images', 'files' => $tokens, 'slots' => $slots, 'uploads' => $ids];
            $this->stats['photos'] = ($this->stats['photos'] ?? 0) + count($tokens);
        }

        return [$parts, array_map('strtolower', array_keys($byLabel))];
    }

    /** One customer photo, kept privately like new uploads. Re-running the import reuses it. */
    private function customerPhoto(string $url, int $wooOrderId): ?CustomerUpload
    {
        $name = Str::limit(basename((string) parse_url($url, PHP_URL_PATH)), 150, '') ?: md5($url).'.jpg';
        $path = 'customer-uploads/woo/'.$wooOrderId.'/'.$name;
        if ($existing = CustomerUpload::where('path', $path)->first()) {
            return $existing;
        }
        try {
            $res = Http::timeout(60)->retry(2, 1000, throw: false)->get($url);
        } catch (\Throwable) {
            $res = null;
        }
        if (! $res || ! $res->successful()) {
            $this->say('    ! customer photo not downloaded: '.$url);

            return null;
        }
        $disk = Storage::disk('local');
        $disk->put($path, $res->body());
        $token = (string) Str::uuid();
        $thumb = 'customer-uploads/woo/'.$wooOrderId.'/'.pathinfo($name, PATHINFO_FILENAME).'_thumb.jpg';
        [$w, $h] = @getimagesize($disk->path($path)) ?: [null, null];

        return CustomerUpload::create([
            'token' => $token,
            'session_id' => 'woo-import',
            'path' => $path,
            'thumb_path' => ImageTools::thumbnail($disk->path($path), $disk->path($thumb), 480) ? $thumb : null,
            'original_name' => $name,
            'mime' => (string) ($res->header('Content-Type') ?: 'image/jpeg'),
            'size' => strlen($res->body()),
            'width' => $w,
            'height' => $h,
        ]);
    }

    private function phone(string $raw): ?string
    {
        $d = substr(preg_replace('/\D/', '', $raw), -10);

        return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
    }

    /** SEO titles/descriptions written in Yoast, when the site runs it. */
    private function yoastMeta(): array
    {
        try {
            $rows = $this->all('/wp-json/wp/v2/product', ['_fields' => 'id,yoast_head_json']);
        } catch (\Throwable) {
            return [];
        }

        return collect($rows)->filter(fn ($r) => ! empty($r['yoast_head_json']))->mapWithKeys(fn ($r) => [$r['id'] => [
            'title' => $r['yoast_head_json']['title'] ?? null,
            'description' => $r['yoast_head_json']['description'] ?? $r['yoast_head_json']['og_description'] ?? null,
        ]])->all();
    }

    /** Page-builder shortcodes mean nothing outside WordPress. */
    private function cleanHtml(string $html): string
    {
        // Page-builder <style>/<script> blocks would otherwise show up as text.
        $html = preg_replace('#<(style|script|noscript)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('/\[\/?[a-z_\-]+[^\]]*\]/i', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        return trim(preg_replace('/\s(class|style|id|data-[a-z\-]+)="[^"]*"/i', '', $html));
    }

    /** Download an image to the public disk (once). Returns the local path, or the source URL if it cannot be fetched. */
    public function image(string $src, string $dir): string
    {
        if (! $this->downloadImages || $this->keepRemote($src)) {
            return $src;
        }
        $name = Str::slug(pathinfo(parse_url($src, PHP_URL_PATH), PATHINFO_FILENAME)) ?: md5($src);
        $ext = strtolower(pathinfo(parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
        $path = trim($dir, '/').'/'.$name.'.'.$ext;
        $disk = media_disk();
        if ($disk->exists($path)) {
            return $path;
        }
        try {
            $res = Http::timeout(60)->retry(2, 1000, throw: false)->get($src);
        } catch (\Throwable) {
            $res = null; // host unreachable: keep the remote URL, a later run downloads it
        }
        if (! $res || ! $res->successful() || ! str_starts_with((string) $res->header('Content-Type'), 'image/')) {
            $this->say('    ! image not downloaded, keeping remote URL: '.$src);

            return $src;
        }
        $disk->put($path, $res->body());
        // A WebP copy alongside (on this server only), served by <picture> when the browser supports it.
        if (config('store.media_disk') === 'public' && in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            ImageTools::variants($disk->path($path));
        }

        return $path;
    }

    /** Images on the DigitalOcean Spaces CDN (or another host in MEDIA_KEEP_HOSTS) stay where they are. */
    private function keepRemote(string $src): bool
    {
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        foreach (config('store.media_keep_hosts', []) as $keep) {
            if ($host === $keep || str_ends_with($host, '.'.$keep)) {
                return true;
            }
        }

        return false;
    }

    /** If WooCommerce printed a URL that differs from ours (custom permalink base, non-ASCII slug), keep it working. */
    private function keepOldUrl(?string $old, string $new): void
    {
        if (! $old) {
            return;
        }
        $oldPath = Redirect::normalise((string) parse_url($old, PHP_URL_PATH));
        $newPath = Redirect::normalise((string) parse_url($new, PHP_URL_PATH));
        if ($oldPath !== $newPath && $oldPath !== '/') {
            Redirect::updateOrCreate(['from_path' => $oldPath], ['to_path' => rtrim(parse_url($new, PHP_URL_PATH), '/').'/', 'status' => 301]);
            $this->stats['redirects']++;
        }
    }

    /**
     * Fallback when the Store API is blocked: WooCommerce → Products → Export (CSV, all columns).
     */
    public function importCsv(string $file): void
    {
        $fh = fopen($file, 'r');
        $head = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), fgetcsv($fh));
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) === count($head)) {
                $rows[] = array_combine($head, $r);
            }
        }
        fclose($fh);

        $parents = [];
        foreach ($rows as $r) {
            $type = strtolower($r['Type'] ?? 'simple');
            if (str_contains($type, 'variation')) {
                continue;
            }
            $catIds = [];
            foreach (array_filter(array_map('trim', explode(',', $r['Categories'] ?? ''))) as $chain) {
                $parent = null;
                foreach (array_map('trim', explode('>', $chain)) as $name) {
                    $slug = Str::slug(html_entity_decode($name));
                    $cat = Category::firstOrCreate(['slug' => $slug], ['name' => html_entity_decode($name), 'parent_id' => $parent?->id]);
                    $parent = $cat;
                }
                $catIds[] = $parent->id;
            }
            $slug = Str::slug($r['Name']);
            if (preg_match('#/product/([^/]+)/?#', $r['Permalink'] ?? '', $m)) {
                $slug = rawurldecode($m[1]);
            }
            $regular = (float) ($r['Regular price'] ?? 0);
            $sale = (float) ($r['Sale price'] ?? 0);
            $p = Product::updateOrCreate(['woo_id' => (int) $r['ID']], [
                'name' => $r['Name'], 'slug' => $this->uniqueSlug($slug ?: 'product-'.$r['ID'], Product::where('woo_id', (int) $r['ID'])->value('id')), 'sku' => $r['SKU'] ?: null,
                'is_active' => ($r['Published'] ?? '1') === '1',   // WooCommerce export: 1 published, 0 private, -1 draft
                'type' => str_contains($type, 'variable') ? 'variable' : 'simple',
                'price' => $regular, 'sale_price' => $sale > 0 && $sale < $regular ? $sale : null,
                'short_description' => $r['Short description'] ?? null, 'description' => $r['Description'] ?? null,
                'is_featured' => ($r['Is featured?'] ?? '0') === '1', 'in_stock' => ($r['In stock?'] ?? '1') !== '0',
                'weight_kg' => is_numeric($r['Weight (kg)'] ?? null) ? (float) $r['Weight (kg)'] : null,
                'primary_category_id' => $catIds[0] ?? null,
                'meta_keywords' => $r['Tags'] ?? null,
            ]);
            $p->categories()->sync($catIds);
            if ($p->images()->doesntExist()) {
                foreach (array_values(array_filter(array_map('trim', explode(',', $r['Images'] ?? '')))) as $i => $src) {
                    $created = ProductImage::create(['product_id' => $p->id, 'path' => $this->image($src, 'products/'.$p->slug), 'source_url' => $src, 'alt' => $p->name, 'position' => $i]);
                    Media::register($created->path, 'products', $p->name);
                }
            }
            $p->productAttributes()->delete();
            for ($n = 1; $n <= 10 && isset($r["Attribute {$n} name"]); $n++) {
                if ($r["Attribute {$n} name"] === '') {
                    continue;
                }
                $p->productAttributes()->create([
                    'name' => $r["Attribute {$n} name"], 'values' => array_map('trim', explode(',', $r["Attribute {$n} value(s)"] ?? '')),
                    'is_variation' => true, 'position' => $n,
                ]);
            }
            $parents['id:'.$r['ID']] = $p;
            if ($r['SKU']) {
                $parents[$r['SKU']] = $p;
            }
            if ($this->addUploadFields && $p->customizationFields()->doesntExist()) {
                $this->defaultCustomization($p);
            }
            $this->stats['products']++;
        }

        foreach ($rows as $i => $r) {
            if (! str_contains(strtolower($r['Type'] ?? ''), 'variation') || ! ($p = $parents[$r['Parent'] ?? ''] ?? null)) {
                continue;
            }
            $options = [];
            for ($n = 1; $n <= 10 && isset($r["Attribute {$n} name"]); $n++) {
                if ($r["Attribute {$n} name"] !== '') {
                    $options[$r["Attribute {$n} name"]] = $r["Attribute {$n} value(s)"];
                }
            }
            $regular = (float) $r['Regular price'];
            $sale = (float) ($r['Sale price'] ?? 0);
            $variant = ProductVariant::updateOrCreate(['woo_id' => (int) $r['ID']], [
                'product_id' => $p->id, 'options' => $options, 'sku' => $r['SKU'] ?: null,
                'price' => $regular, 'sale_price' => $sale > 0 && $sale < $regular ? $sale : null,
                'in_stock' => ($r['In stock?'] ?? '1') !== '0', 'position' => $i,
                'image' => ($src = Arr::first(array_filter(explode(',', $r['Images'] ?? '')))) ? $this->image(trim($src), 'products/'.$p->slug) : null,
            ]);
            if ($variant->image) {
                Media::register($variant->image, 'products');
            }
            $this->stats['variants']++;
        }
    }
}

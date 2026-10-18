<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CustomizationField;
use App\Models\Media;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Redirect;
use App\Models\Review;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
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
            ImageTools::toWebp($disk->path($path), $disk->path(preg_replace('/\.\w+$/', '.webp', $path)));
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

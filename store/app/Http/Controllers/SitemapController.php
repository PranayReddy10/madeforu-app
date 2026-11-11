<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Carbon\Carbon;

class SitemapController extends Controller
{
    public function index()
    {
        $last = fn ($q) => optional($q->max('updated_at'), fn ($d) => Carbon::parse($d)->toAtomString());
        $maps = [
            ['loc' => url('sitemap-products.xml'), 'lastmod' => $last(Product::active())],
            ['loc' => url('sitemap-categories.xml'), 'lastmod' => $last(Category::active())],
            ['loc' => url('sitemap-pages.xml'), 'lastmod' => $last(Page::where('is_active', true))],
        ];

        return response()->view('sitemaps.index', compact('maps'))->header('Content-Type', 'application/xml');
    }

    public function show(string $type)
    {
        $urls = match ($type) {
            'products', 'images' => Product::active()->where('noindex', false)->with('images')->latest('updated_at')->get()->map(fn ($p) => [
                'loc' => $p->url(), 'lastmod' => $p->updated_at, 'priority' => '0.8', 'changefreq' => 'weekly',
                'images' => $p->images->take(10)->map(fn ($i) => ['loc' => $i->url(), 'title' => $i->alt ?: $p->name])->all(),
            ]),
            'categories' => Category::active()->get()->map(fn ($c) => [
                'loc' => $c->url(), 'lastmod' => $c->updated_at, 'priority' => $c->parent_id ? '0.6' : '0.7', 'changefreq' => 'weekly', 'images' => [],
            ]),
            'pages' => collect([
                ['loc' => url('/'), 'lastmod' => now(), 'priority' => '1.0', 'changefreq' => 'daily', 'images' => []],
                ['loc' => url('shop').'/', 'lastmod' => now(), 'priority' => '0.9', 'changefreq' => 'daily', 'images' => []],
                ['loc' => route('track'), 'lastmod' => null, 'priority' => '0.3', 'changefreq' => 'yearly', 'images' => []],
            ])->concat(Page::where('is_active', true)->get()->map(fn ($p) => [
                'loc' => $p->url(), 'lastmod' => $p->updated_at, 'priority' => '0.4', 'changefreq' => 'monthly', 'images' => [],
            ])),
        };

        return response()->view('sitemaps.urls', compact('urls'))->header('Content-Type', 'application/xml');
    }

    public function robots()
    {
        $lines = app()->isProduction() ? [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /my-account',
            'Disallow: /uploads/',
            'Disallow: /search',
            'Disallow: /*?*orderby=',
            'Disallow: /*?*min=',
            'Allow: /',
            '',
            'Sitemap: '.url('sitemap.xml'),
        ] : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain']);
    }

    /** IndexNow (Bing, Yandex, Seznam): the key file must be served at /{key}.txt. */
    public function indexNowKey(string $key)
    {
        abort_unless($key === config('store.analytics.indexnow_key'), 404);

        return response($key, 200, ['Content-Type' => 'text/plain']);
    }
}

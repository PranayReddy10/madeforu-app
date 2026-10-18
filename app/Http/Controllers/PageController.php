<?php

namespace App\Http\Controllers;

use App\Http\Middleware\WordPressUrls;
use App\Models\Page;
use App\Services\Seo;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function show(Request $request, string $slug, Seo $seo)
    {
        $page = Page::where('slug', $slug)->where('is_active', true)->firstOrFail();
        if (! str_ends_with($request->getPathInfo(), '/')) {
            return WordPressUrls::slashed($request);
        }
        $seo->title($page->meta_title ?: $page->title)
            ->description($page->meta_description ?: strip_tags((string) $page->body))
            ->canonical($page->url());
        $seo->breadcrumbs([['Home', url('/')], [$page->title, $page->url()]]);

        return view('page', compact('page'));
    }

    public function manifest()
    {
        return response()->json([
            'name' => config('store.name'),
            'short_name' => config('store.name'),
            'description' => config('store.tagline'),
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#fffaf5',
            'theme_color' => '#e8445a',
            'icons' => [
                ['src' => asset('images/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('images/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Shop', 'url' => '/shop/'],
                ['name' => 'Cart', 'url' => '/cart'],
                ['name' => 'Track order', 'url' => '/track-order'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}

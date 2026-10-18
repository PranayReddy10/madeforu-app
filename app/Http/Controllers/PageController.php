<?php

namespace App\Http\Controllers;

use App\Http\Middleware\WordPressUrls;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Services\Seo;
use App\Support\DefaultPages;
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
        $crumbs = [['Home', url('/')]];
        if (str_starts_with($page->slug, 'legal/') && ($index = Page::where('slug', 'legal')->where('is_active', true)->first())) {
            $crumbs[] = ['Policies', $index->url()];
        }
        $crumbs[] = [$page->title, $page->url()];
        $seo->breadcrumbs($crumbs);

        $template = $page->template ?: DefaultPages::templateFor($page->slug);
        $extra = match ($template) {
            'legal', 'legal-index' => ['policies' => Page::where('slug', 'like', 'legal/%')->where('is_active', true)->orderBy('title')->get()],
            'about' => [
                'showcase' => Product::active()->with('images')->has('images')->orderByDesc('sales_count')->orderByDesc('views')->take(4)->get(),
                'reviews' => Review::where('is_approved', true)->where('rating', '>=', 4)->whereNotNull('body')->latest()->take(3)->get(),
            ],
            default => [],
        };

        return view('page', compact('page', 'template', 'crumbs') + $extra);
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
            'background_color' => '#ffffff',
            'theme_color' => '#ffffff',
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

<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\Page;
use App\Services\Cart;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ShareStoreData
{
    public function handle(Request $request, Closure $next): Response
    {
        self::share();

        return $next($request);
    }

    /**
     * Cart count, menu and footer links for the shop layout. Also called by the layout itself
     * (AppServiceProvider) when no web route ran, e.g. the 404 page for /wp-login.php.
     */
    public static function share(): void
    {
        View::share('cartCount', app(Cart::class)->count());
        View::share('menuCategories', Cache::remember('menu.categories', 600, function () {
            $shown = Category::withShopProductsIds();

            return Category::active()->whereNull('parent_id')->whereIn('id', $shown)
                ->with(['children' => fn ($q) => $q->active()->whereIn('id', $shown)])
                ->orderBy('position')->orderBy('name')->get();
        }));

        // Footer/help links: the store's real CMS pages, whatever WordPress called them.
        View::share('footerPages', Cache::remember('menu.pages', 600, fn () => Page::where('is_active', true)
            ->whereNotIn('slug', ['legal'])->orderByRaw("slug like 'legal/%'")->orderBy('title')->get(['title', 'slug'])
            ->map(fn ($p) => ['title' => $p->title, 'url' => $p->url()])->all()));
    }
}

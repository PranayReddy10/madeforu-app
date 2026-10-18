<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\Product;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps WordPress/WooCommerce URL behaviour so nothing indexed today breaks:
 *  - pages end in a slash (/product/slug/), the way WordPress prints them; /product/slug 301s to it
 *  - query-string permalinks (?product=, ?product_cat=, ?p=, ?s=) 301 to the pretty URL
 *  - UTM parameters are remembered for the order record
 */
class WordPressUrls
{
    /** Paths WordPress served with a trailing slash. CMS pages (/about-us/) are handled in PageController. */
    private const SLASHED = ['product/*', 'product-category/*', 'shop', 'shop/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            if ($r = $this->legacyQuery($request)) {
                return $r;
            }
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid'] as $k) {
                if ($request->query($k)) {
                    session(['utm' => array_merge(session('utm', []), [$k => substr((string) $request->query($k), 0, 200)])]);
                }
            }

            $pathInfo = $request->getPathInfo();
            if (! str_ends_with($pathInfo, '/') && $request->is(...self::SLASHED) && ! $request->expectsJson()) {
                return static::slashed($request);
            }
        }

        return $next($request);
    }

    public static function slashed(Request $request): Response
    {
        $qs = $request->getQueryString();

        return redirect()->to($request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'/'.($qs ? '?'.$qs : ''), 301);
    }

    private function legacyQuery(Request $request): ?Response
    {
        if ($slug = $request->query('product')) {
            return redirect(url('product/'.$slug).'/', 301);
        }
        if ($slug = $request->query('product_cat')) {
            $c = Category::where('slug', $slug)->first();

            return redirect($c ? $c->url() : url('shop').'/', 301);
        }
        if ($request->path() === '/' && ($id = $request->query('p') ?: $request->query('page_id'))) {
            $p = Product::where('woo_id', (int) $id)->first();

            return redirect($p ? $p->url() : url('/'), 301);
        }
        if ($request->path() === '/' && ($s = $request->query('s'))) {
            return redirect(route('search', ['q' => $s]), 301);
        }

        return null;
    }
}

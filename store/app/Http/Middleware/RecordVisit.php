<?php

namespace App\Http\Middleware;

use App\Services\Visits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Counts storefront page views (HTML pages only; not admin, files, JSON or bots). */
class RecordVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethod('GET') && $response->getStatusCode() === 200 && ! $request->expectsJson()
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $request->is('admin', 'admin/*', 'uploads/*', 'cart/recover/*')) {
            $type = $request->routeIs('product') ? 'product' : ($request->routeIs('checkout') ? 'checkout' : 'view');
            Visits::record($request, $type, ['product_id' => $request->attributes->get('visit_product')]);
        }

        return $response;
    }
}

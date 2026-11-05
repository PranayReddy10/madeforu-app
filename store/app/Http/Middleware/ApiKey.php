<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The catalogue API is open (it's the same data the shop pages show) until an API key is set in
 * Admin → Settings → Product API; then every call needs it as ?key=… or an X-Api-Key header.
 * Any website may call it from the browser (CORS), since it only reads.
 */
class ApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) setting('api.key');
        if ($key !== '' && ! hash_equals($key, (string) ($request->header('X-Api-Key') ?: $request->query('key')))) {
            return response()->json(['error' => 'Missing or wrong API key. Send it as ?key=… or an X-Api-Key header.'], 401)
                ->header('Access-Control-Allow-Origin', '*');
        }
        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Headers', 'X-Api-Key, Content-Type');

        return $response;
    }
}

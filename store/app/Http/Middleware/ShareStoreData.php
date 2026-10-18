<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Services\Cart;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ShareStoreData
{
    public function __construct(private Cart $cart) {}

    public function handle(Request $request, Closure $next): Response
    {
        View::share('cartCount', $this->cart->count());
        View::share('menuCategories', Cache::remember('menu.categories', 600, fn () => Category::active()
            ->whereNull('parent_id')->with(['children' => fn ($q) => $q->active()])
            ->orderBy('position')->orderBy('name')->get()));

        return $next($request);
    }
}

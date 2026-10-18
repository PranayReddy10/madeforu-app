<?php

namespace App\Providers;

use App\Services\Analytics;
use App\Services\Cart;
use App\Services\Seo;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Seo::class);
        $this->app->scoped(Cart::class);
        $this->app->scoped(Analytics::class);
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
        Paginator::defaultView('partials.pagination');
        View::composer('*', function ($view) {
            $view->with('seo', app(Seo::class))->with('analytics', app(Analytics::class));
        });
    }
}

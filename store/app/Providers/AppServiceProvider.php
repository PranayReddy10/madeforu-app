<?php

namespace App\Providers;

use App\Services\Analytics;
use App\Services\Cart;
use App\Services\Seo;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('Set your '.config('store.name').' password')
            ->greeting('Hi '.Str::before((string) $user->name, ' ').'!')
            ->line('Tap the button to set a new password for your '.config('store.name').' account. The link works for 60 minutes.')
            ->action('Set my password', route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->line('Didn\'t ask for this? You can ignore this email — your account is safe.'));
        View::composer('*', function ($view) {
            $view->with('seo', app(Seo::class))->with('analytics', app(Analytics::class));
        });
    }
}

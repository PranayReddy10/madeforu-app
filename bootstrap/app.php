<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\RecordVisit;
use App\Http\Middleware\ShareStoreData;
use App\Http\Middleware\WordPressUrls;
use App\Models\Redirect;
use App\Services\Visits;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => EnsureAdmin::class]);
        $middleware->web(prepend: [WordPressUrls::class]);
        $middleware->web(append: [ShareStoreData::class, RecordVisit::class]);
        $middleware->encryptCookies(except: [Visits::COOKIE]);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Before showing a 404, look for a saved redirect (old WordPress URLs, renamed slugs).
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->isMethod('GET')) {
                return null;
            }
            try {
                $r = Redirect::where('from_path', Redirect::normalise($request->getPathInfo()))->first();
            } catch (Throwable) {
                return null;
            }
            if ($r) {
                $r->increment('hits');

                return redirect($r->to_path, $r->status);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * After uploading a new version: is the database behind the code (migrations not run), or is the
 * server still using a cached list of routes from an older version? Admin shows a banner with a
 * one-click fix, so no terminal is needed on shared hosting.
 */
class SiteHealth
{
    /** @var array{0: int, 1: array<int, string>}|null  [request id, pending] — checked once per page */
    private static ?array $pending = null;

    /** Migration files not yet run. */
    public static function pendingMigrations(): array
    {
        $req = spl_object_id(request());
        if (self::$pending !== null && self::$pending[0] === $req) {
            return self::$pending[1];
        }
        try {
            $m = app('migrator');
            $list = $m->repositoryExists()
                ? array_values(array_diff(array_keys($m->getMigrationFiles(database_path('migrations'))), $m->getRepository()->getRan()))
                : [];
        } catch (\Throwable) {
            $list = [];
        }
        self::$pending = [$req, $list];

        return $list;
    }

    /** Routes are cached, but from an older version (a route this version defines is missing). */
    public static function staleRoutes(): bool
    {
        return app()->routesAreCached() && ! Route::has('admin.orders.charges');
    }

    public static function needsUpdate(): bool
    {
        return self::pendingMigrations() !== [] || self::staleRoutes();
    }

    /** migrate + clear the cached routes, settings and pages. Returns a short report. */
    public static function update(): string
    {
        @set_time_limit(300);
        $pending = count(self::pendingMigrations());
        Artisan::call('migrate', ['--force' => true]);
        foreach (['route:clear', 'config:clear', 'view:clear'] as $cmd) {
            Artisan::call($cmd);
        }
        try {
            Artisan::call('view:cache');   // pages pre-built again, as Speed does
        } catch (\Throwable) {
        }
        self::$pending = null;

        return 'Site updated: '.($pending ? $pending.' database '.($pending === 1 ? 'change' : 'changes').' applied' : 'database already up to date')
            .', cached routes and pages refreshed.';
    }
}

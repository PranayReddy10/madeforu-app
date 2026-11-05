<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/** Finds why the site shows "500 Server Error" on a new server: PHP, extensions, .env, folders, database, last error. */
class CheckServer extends Command
{
    protected $signature = 'store:check';

    protected $description = 'Check this server can run the store and show the last error';

    private int $problems = 0;

    public function handle(): int
    {
        $this->ok(PHP_VERSION_ID >= 80300, 'PHP '.PHP_VERSION.' (command line)', 'needs 8.3 or newer: hPanel → Advanced → PHP Configuration. The website and SSH can use different versions, so set it for the domain too.');
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'fileinfo', 'dom', 'gd', 'zip', 'tokenizer', 'xml', 'ctype'] as $ext) {
            $this->ok(extension_loaded($ext), "PHP extension {$ext}", 'turn it on in hPanel → Advanced → PHP Configuration → PHP extensions.');
        }

        $this->ok(File::exists(base_path('.env')), '.env file', 'copy .env.example to .env and fill it in.');
        $this->ok((bool) config('app.key'), 'APP_KEY set', 'run: php artisan key:generate');
        $this->ok(! (bool) config('app.debug') || config('app.env') !== 'production', 'APP_DEBUG off in production', 'set APP_DEBUG=false once the site works (true shows errors to everyone).');
        $this->line('  APP_URL = '.config('app.url'));
        // After moving the site to another folder, cached settings still point at the old one.
        $stale = collect((array) config('view.paths'))->push(config('view.compiled'))->filter()
            ->first(fn ($p) => ! str_starts_with((string) $p, base_path()) || ! is_dir((string) $p));
        $this->ok(! $stale, 'Cached paths match this folder', "they point to {$stale} — run: rm -f bootstrap/cache/*.php && php artisan optimize");
        $link = public_path('storage');
        if (is_link($link)) {
            $this->ok(is_dir($link), 'public/storage link', 'it points to a folder that no longer exists — run: rm public/storage && ln -s ../storage/app/public public/storage');
        }
        if (File::exists(base_path('bootstrap/cache/config.php'))) {
            $this->warn('  Settings are cached: after every .env change run  php artisan optimize:clear && php artisan optimize');
        }

        foreach (['storage', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $path = base_path($dir);
            File::ensureDirectoryExists($path);
            $this->ok(is_writable($path), "{$dir} writable", "run: chmod -R 775 {$dir}");
        }
        $this->ok(File::exists(base_path('vendor/autoload.php')), 'vendor/ installed', 'run: composer install --no-dev -o');

        try {
            DB::connection()->getPdo();
            $this->ok(true, 'Database connection ('.config('database.default').': '.config('database.connections.'.config('database.default').'.database').')', '');
            $this->ok(Schema::hasTable('products') && Schema::hasTable('settings'), 'Database tables', 'run: php artisan migrate --force');
            if (Schema::hasTable('migrations')) {
                Artisan::call('migrate:status', ['--pending' => true]);
                $pending = substr_count(Artisan::output(), 'Pending');
                $this->ok($pending === 0, 'Migrations up to date', "{$pending} waiting: run php artisan migrate --force");
            }
        } catch (\Throwable $e) {
            $this->ok(false, 'Database connection', 'check DB_HOST (usually localhost), DB_DATABASE, DB_USERNAME (Hostinger adds a u123_ prefix to both) and DB_PASSWORD in .env. '.$e->getMessage());
        }

        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        if ($disabled) {
            $this->line('  Disabled PHP functions (fine): '.implode(', ', array_slice($disabled, 0, 12)).(count($disabled) > 12 ? '…' : ''));
        }

        $log = storage_path('logs/laravel.log');
        if (File::exists($log)) {
            $text = File::get($log);
            if (preg_match_all('/^\[(\d{4}-\d\d-\d\d [\d:]+)\] \w+\.(ERROR|CRITICAL|EMERGENCY): (.+)$/m', $text, $m)) {
                $last = count($m[0]) - 1;
                $this->newLine();
                $this->warn('Last error in storage/logs/laravel.log ('.$m[1][$last].'):');
                $this->line('  '.mb_strimwidth($m[3][$last], 0, 900, '…'));
            }
        } else {
            $this->line('  No storage/logs/laravel.log yet. If the site still shows 500, the error happens before Laravel starts: check hPanel → Advanced → Error logs, the PHP version for the domain, and that public/index.php exists.');
        }

        $this->newLine();
        $this->problems ? $this->error($this->problems.' problem(s) above.') : $this->info('All checks passed.');

        return $this->problems ? self::FAILURE : self::SUCCESS;
    }

    private function ok(bool $pass, string $what, string $fix): void
    {
        if ($pass) {
            $this->line("  <info>✓</info> {$what}");
        } else {
            $this->problems++;
            $this->line("  <error>✗</error> {$what} — {$fix}");
        }
    }
}

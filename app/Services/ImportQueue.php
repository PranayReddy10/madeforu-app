<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;

/**
 * A WooCommerce import asked for from Admin → Import. The request (API keys encrypted) waits in a
 * file until the scheduler cron picks it up, or the admin presses "Run now" to run it in the browser.
 * The keys are deleted as soon as the run ends.
 */
class ImportQueue
{
    public static function stateFile(): string
    {
        return storage_path('app/private/woo-import.json');
    }

    public static function logFile(): string
    {
        return storage_path('logs/woo-import.log');
    }

    /** @return array{status?: string, url?: string, customers?: bool, images?: bool, queued_at?: string, started_at?: string, finished_at?: string, stats?: array, error?: ?string, has_keys?: bool} */
    public static function state(): array
    {
        $s = File::exists(self::stateFile()) ? (json_decode(File::get(self::stateFile()), true) ?: []) : [];
        $s['has_keys'] = ! empty($s['keys']);
        unset($s['keys']);

        return $s;
    }

    private static function raw(): array
    {
        return File::exists(self::stateFile()) ? (json_decode(File::get(self::stateFile()), true) ?: []) : [];
    }

    private static function save(array $s): void
    {
        File::ensureDirectoryExists(dirname(self::stateFile()));
        File::put(self::stateFile(), json_encode($s, JSON_PRETTY_PRINT));
    }

    /**
     * Waiting or running. A run whose log has been silent for 10 minutes was killed (PHP time limit,
     * server restart) and no longer counts, so it can be started again; the importer skips what it already copied.
     */
    public static function busy(): bool
    {
        $status = self::raw()['status'] ?? null;
        if ($status === 'queued') {
            return true;
        }

        return $status === 'running' && File::exists(self::logFile()) && File::lastModified(self::logFile()) > time() - 600;
    }

    public static function queue(string $url, ?string $key, ?string $secret, bool $customers, bool $images): void
    {
        Cache::lock('woo-import')->forceRelease();
        self::save([
            'status' => 'queued',
            'url' => $url,
            'customers' => $customers,
            'images' => $images,
            'keys' => $key && $secret ? Crypt::encryptString(json_encode([$key, $secret])) : null,
            'queued_at' => now()->toDateTimeString(),
        ]);
        File::ensureDirectoryExists(dirname(self::logFile()));
        File::put(self::logFile(), '['.now()->format('H:i:s')."] Import queued for {$url}\n");
    }

    public static function log(string $line): void
    {
        File::append(self::logFile(), '['.now()->format('H:i:s').'] '.$line."\n");
    }

    public static function logTail(int $lines = 200): string
    {
        if (! File::exists(self::logFile())) {
            return '';
        }

        return implode("\n", array_slice(explode("\n", rtrim(File::get(self::logFile()))), -$lines));
    }

    /** Run the queued import, if there is one. Returns false when nothing was waiting. */
    public static function runQueued(): bool
    {
        $s = self::raw();
        if (($s['status'] ?? null) !== 'queued') {
            return false;
        }
        $lock = Cache::lock('woo-import', 1800);
        if (! $lock->get()) {
            return false;
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        try {
            $s['status'] = 'running';
            $s['started_at'] = now()->toDateTimeString();
            self::save($s);
            [$key, $secret] = $s['keys'] ? json_decode(Crypt::decryptString($s['keys']), true) : [null, null];

            $importer = new WooImporter($s['url'], $key, $secret);
            $importer->downloadImages = (bool) $s['images'];
            $importer->log = fn ($m) => self::log($m);
            self::log($importer->usesApiKeys() ? 'Using REST API keys: published and draft products.' : 'No API keys: published products only.');
            $importer->run();
            if ($s['customers'] && $importer->usesApiKeys()) {
                $importer->importCustomers();
                $importer->importOrders();
                $importer->importCoupons();
            }
            Cache::forget('menu.categories');
            Cache::forget('menu.pages');
            $s['status'] = 'done';
            $s['stats'] = $importer->stats;
            $s['error'] = null;
            self::log('Done: '.collect($importer->stats)->map(fn ($v, $k) => "{$v} {$k}")->implode(', '));
        } catch (\Throwable $e) {
            $s['status'] = 'failed';
            $s['error'] = $e->getMessage();
            self::log('FAILED: '.$e->getMessage());
        } finally {
            $s['keys'] = null;          // never keep the WooCommerce keys after a run
            $s['finished_at'] = now()->toDateTimeString();
            self::save($s);
            $lock->release();
        }

        return true;
    }
}

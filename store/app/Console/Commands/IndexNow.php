<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Tell Bing (and Yandex, Seznam, Naver) about new or changed pages straight away. Google reads the sitemap. */
class IndexNow extends Command
{
    protected $signature = 'store:indexnow {--since=1 : Only pages changed in the last N days (0 = everything)}';

    protected $description = 'Submit changed product and category URLs to IndexNow';

    public function handle(): int
    {
        $key = config('store.analytics.indexnow_key');
        if (! $key) {
            $this->line('INDEXNOW_KEY is not set; skipping.');

            return self::SUCCESS;
        }
        $days = (int) $this->option('since');
        $q = fn ($m) => $days > 0 ? $m->where('updated_at', '>=', now()->subDays($days)) : $m;
        $urls = $q(Product::active()->where('noindex', false))->get()->map->url()
            ->concat($q(Category::active())->get()->map->url())->values()->all();
        if (! $urls) {
            $this->info('Nothing changed.');

            return self::SUCCESS;
        }
        foreach (array_chunk($urls, 10000) as $chunk) {
            $res = Http::post('https://api.indexnow.org/indexnow', [
                'host' => parse_url(config('app.url'), PHP_URL_HOST),
                'key' => $key,
                'keyLocation' => url($key.'.txt'),
                'urlList' => $chunk,
            ]);
            $this->line('IndexNow: HTTP '.$res->status().' for '.count($chunk).' URLs');
        }

        return self::SUCCESS;
    }
}

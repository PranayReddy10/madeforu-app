<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Services\Google;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckIndexStatus extends Command
{
    protected $signature = 'store:index-check {--limit=60 : Pages to check this run (Google allows 2,000 a day)}';

    protected $description = 'Ask Google Search Console whether each product, category and page is indexed';

    public function handle(): int
    {
        if (! Google::searchReady()) {
            $this->line('Search Console is not set up (Admin → Settings → Google).');

            return self::SUCCESS;
        }
        $urls = collect([[url('/').'/', 'home']])
            ->concat(Product::active()->get()->map(fn ($p) => [$p->url(), 'product']))
            ->concat(Category::active()->get()->map(fn ($c) => [$c->url(), 'category']))
            ->concat(Page::where('is_active', true)->get()->map(fn ($p) => [$p->url(), 'page']));
        foreach ($urls as [$u, $kind]) {
            DB::table('search_index_status')->insertOrIgnore(['url' => $u, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Pages the shop no longer has drop out of the report.
        DB::table('search_index_status')->whereNotIn('url', $urls->pluck(0))->delete();

        $due = DB::table('search_index_status')->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subDays(5)))
            ->orderByRaw('checked_at is not null')->orderBy('checked_at')->limit((int) $this->option('limit'))->pluck('url');
        $google = app(Google::class);
        foreach ($due as $u) {
            try {
                $r = $google->inspect($u);
                DB::table('search_index_status')->where('url', $u)->update([
                    'verdict' => $r['verdict'] ?? null,
                    'coverage' => $r['coverageState'] ?? null,
                    'last_crawl_at' => ! empty($r['lastCrawlTime']) ? Carbon::parse($r['lastCrawlTime']) : null,
                    'checked_at' => now(), 'updated_at' => now(),
                ]);
                $this->line(($r['verdict'] ?? '?').'  '.$u);
            } catch (\Throwable $e) {
                $this->error($u.': '.$e->getMessage());
                if (str_contains($e->getMessage(), 'quota') || str_contains($e->getMessage(), 'sign-in')) {
                    break;
                }
            }
            usleep(400000);
        }

        return self::SUCCESS;
    }
}

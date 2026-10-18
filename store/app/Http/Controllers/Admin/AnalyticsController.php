<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SearchQuery;
use App\Services\Google;
use App\Services\Instagram;
use App\Services\VisitReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $days = in_array((int) $request->query('days'), [1, 7, 30, 90], true) ? (int) $request->query('days') : 30;
        $r = new VisitReport($days);

        return view('admin.analytics', [
            'days' => $days,
            'kpis' => $r->kpis(),
            'daily' => $r->daily(),
            'sources' => $r->sources(),
            'devices' => $r->devices(),
            'pages' => $r->topPages(),
            'products' => $r->products(),
            'google' => $this->google($days),
            'index' => $this->indexStatus(),
            'feeds' => [
                'google' => Cache::get('feed.fetched.google'),
                'facebook' => Cache::get('feed.fetched.facebook'),
                'products' => Product::active()->count(),
                'pixel' => (bool) setting('analytics.meta_pixel'),
                'ga4_tag' => (bool) (setting('analytics.ga4') || setting('analytics.gtm')),
                'instagram' => Instagram::enabled(),
                'instagram_posts' => count(Instagram::posts()),
            ],
            'searches' => SearchQuery::selectRaw('term, count(*) as n, max(results) as results')->where('created_at', '>=', now()->subDays($days))
                ->groupBy('term')->orderByDesc('n')->take(12)->get(),
        ]);
    }

    /** GA4 and Search Console numbers, cached for an hour (Refresh clears it). */
    private function google(int $days): array
    {
        return Cache::remember('admin.google-report.'.$days, 3600, function () use ($days) {
            $out = ['ga' => null, 'ga_error' => null, 'gsc' => null, 'gsc_error' => null];
            $g = app(Google::class);
            if (Google::analyticsReady()) {
                try {
                    $out['ga'] = [
                        'totals' => $g->analytics([], ['totalUsers', 'sessions', 'screenPageViews', 'engagementRate', 'averageSessionDuration', 'conversions'], $days, 1)[0]['mets'] ?? [],
                        'channels' => $g->analytics(['sessionDefaultChannelGroup'], ['sessions', 'totalUsers', 'conversions'], $days, 8, 'sessions'),
                        'pages' => $g->analytics(['pagePath'], ['screenPageViews', 'totalUsers'], $days, 10, 'screenPageViews'),
                        'cities' => $g->analytics(['city'], ['totalUsers'], $days, 8, 'totalUsers'),
                    ];
                } catch (\Throwable $e) {
                    $out['ga_error'] = $e->getMessage();
                }
            }
            if (Google::searchReady()) {
                try {
                    $totals = $g->search([], $days, 1)[0] ?? [];
                    $out['gsc'] = [
                        'totals' => $totals,
                        'queries' => $g->search(['query'], $days, 15),
                        'pages' => $g->search(['page'], $days, 10),
                        'sitemaps' => $g->sitemaps(),
                    ];
                } catch (\Throwable $e) {
                    $out['gsc_error'] = $e->getMessage();
                }
            }

            return $out;
        });
    }

    private function indexStatus(): array
    {
        $rows = DB::table('search_index_status')->get();

        return [
            'total' => $rows->count(),
            'checked' => $rows->whereNotNull('checked_at')->count(),
            'indexed' => $rows->where('verdict', 'PASS')->count(),
            'byCoverage' => $rows->whereNotNull('checked_at')->groupBy('coverage')->map->count()->sortDesc(),
            'notIndexed' => $rows->whereNotNull('checked_at')->where('verdict', '!=', 'PASS')->sortBy('kind')->take(40)->values(),
            'lastCheck' => $rows->max('checked_at'),
        ];
    }

    public function refresh()
    {
        foreach ([7, 30, 90] as $d) {
            Cache::forget('admin.google-report.'.$d);
        }
        Cache::forget('instagram.posts');

        return back()->with('status', 'Google and Instagram numbers reloaded.');
    }

    public function submitSitemap()
    {
        try {
            app(Google::class)->submitSitemap(url('sitemap.xml'));
            $this->refresh();

            return back()->with('status', 'Sitemap sent to Google Search Console.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Google said: '.$e->getMessage());
        }
    }

    public function checkIndex()
    {
        @set_time_limit(180);
        Artisan::call('store:index-check', ['--limit' => 25]);

        return back()->with('status', 'Checked up to 25 pages with Google. The rest are checked every night.');
    }
}

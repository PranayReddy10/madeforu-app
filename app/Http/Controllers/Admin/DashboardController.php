<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SearchQuery;
use App\Console\Commands\SpeedUp;
use App\Services\Stock;
use App\Services\VisitReport;
use Illuminate\Database\Eloquent\Builder;

class DashboardController extends Controller
{
    /** "Speed up the website": photos served directly, small WebP copies, views pre-compiled. */
    public function speed()
    {
        @set_time_limit(300);
        \Illuminate\Support\Facades\Artisan::call('store:speed', ['--limit' => 300]);

        return back()->with('status', trim(\Illuminate\Support\Facades\Artisan::output()));
    }

    public function index()
    {
        // Money that counts: paid online, or cash on delivery that is not cancelled.
        $counted = fn () => Order::where('status', '!=', 'cancelled')
            ->where(fn (Builder $q) => $q->where('payment_status', 'paid')->orWhere('payment_method', 'cod'));

        $revenue = [
            'Today' => [(float) $counted()->where('created_at', '>=', today())->sum('total'), $counted()->where('created_at', '>=', today())->count()],
            'Last 7 days' => [(float) $counted()->where('created_at', '>=', today()->subDays(6))->sum('total'), $counted()->where('created_at', '>=', today()->subDays(6))->count()],
            'Last 30 days' => [(float) $counted()->where('created_at', '>=', today()->subDays(29))->sum('total'), $counted()->where('created_at', '>=', today()->subDays(29))->count()],
        ];

        $week = new VisitReport(7);
        $speed = SpeedUp::status();

        return view('admin.dashboard', [
            'revenue' => $revenue,
            'speed' => $speed,
            'profit' => (new \App\Services\ProfitReport(30))->summary(),
            'traffic' => $week->kpis()['now'],
            'topSource' => $week->sources()->first(),
            'byStatus' => Order::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'recent' => Order::latest()->take(10)->get(),
            'lowStock' => ProductVariant::with('product')->whereNotNull('stock_qty')->where('stock_qty', '<=', Stock::lowThreshold())->get()
                ->map(fn ($v) => ['product' => $v->product, 'name' => $v->product?->name.' — '.$v->label(), 'qty' => $v->stock_qty])
                ->concat(Product::whereNotNull('stock_qty')->where('type', 'simple')->where('stock_qty', '<=', Stock::lowThreshold())->get()
                    ->map(fn ($p) => ['product' => $p, 'name' => $p->name, 'qty' => $p->stock_qty]))
                ->filter(fn ($r) => $r['product'])->sortBy('qty')->take(15)->values(),
            'topProducts' => Product::orderByDesc('sales_count')->where('sales_count', '>', 0)->take(10)->get(),
            'topSearches' => SearchQuery::selectRaw('term, count(*) as n, max(results) as results')->where('created_at', '>=', now()->subDays(30))
                ->groupBy('term')->orderByDesc('n')->take(15)->get(),
            'zeroSearches' => SearchQuery::selectRaw('term, count(*) as n')->where('results', 0)->where('created_at', '>=', now()->subDays(30))
                ->groupBy('term')->orderByDesc('n')->take(15)->get(),
        ]);
    }
}

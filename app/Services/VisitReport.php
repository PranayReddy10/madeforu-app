<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Numbers for Admin → Analytics, from analytics_events and orders. */
class VisitReport
{
    public Carbon $from;

    public Carbon $to;

    public function __construct(public int $days = 30)
    {
        $this->to = today();
        $this->from = today()->subDays($days - 1);
    }

    private function events(?Carbon $from = null, ?Carbon $to = null)
    {
        return DB::table('analytics_events')->whereBetween('day', [($from ?? $this->from)->toDateString(), ($to ?? $this->to)->toDateString()]);
    }

    /** Orders that count as sales: paid online, or cash on delivery that isn't cancelled. */
    public static function sales(): Builder
    {
        return Order::whereNull('woo_id')->where('status', '!=', 'cancelled')
            ->where(fn (Builder $q) => $q->where('payment_status', 'paid')->orWhere('payment_method', 'cod'));
    }

    private function kpisFor(Carbon $from, Carbon $to): array
    {
        $e = fn () => $this->events($from, $to);
        $visitors = (int) $e()->distinct()->count('visitor');
        $orders = self::sales()->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        $count = (clone $orders)->count();
        $revenue = (float) (clone $orders)->sum('total');

        return [
            'visitors' => $visitors,
            'views' => (int) $e()->whereIn('type', ['view', 'product', 'checkout'])->count(),
            'product_viewers' => (int) $e()->where('type', 'product')->distinct()->count('visitor'),
            'carts' => (int) $e()->where('type', 'cart')->distinct()->count('visitor'),
            'checkouts' => (int) $e()->where('type', 'checkout')->distinct()->count('visitor'),
            'buyers' => (int) $e()->where('type', 'order')->distinct()->count('visitor'),
            'orders' => $count,
            'revenue' => $revenue,
            'aov' => $count ? $revenue / $count : 0,
            'conversion' => $visitors ? 100 * $count / $visitors : 0,
        ];
    }

    /** This period and the one before it, for "+12% vs previous 30 days". */
    public function kpis(): array
    {
        $now = $this->kpisFor($this->from, $this->to);
        $before = $this->kpisFor($this->from->copy()->subDays($this->days), $this->from->copy()->subDay());
        $change = [];
        foreach ($now as $k => $v) {
            $change[$k] = $before[$k] > 0 ? round(100 * ($v - $before[$k]) / $before[$k]) : null;
        }

        return ['now' => $now, 'change' => $change];
    }

    /** One row per day: visitors, orders, revenue. */
    public function daily(): Collection
    {
        $visitors = $this->events()->selectRaw('day, count(distinct visitor) as n')->groupBy('day')->pluck('n', 'day');
        $orders = self::sales()->whereBetween('created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->get(['created_at', 'total'])->groupBy(fn ($o) => $o->created_at->toDateString());

        return collect(range(0, $this->days - 1))->map(function ($i) use ($visitors, $orders) {
            $d = $this->from->copy()->addDays($i)->toDateString();

            return ['day' => $d, 'visitors' => (int) ($visitors[$d] ?? 0), 'orders' => count($orders[$d] ?? []), 'revenue' => (float) collect($orders[$d] ?? [])->sum('total')];
        });
    }

    /** Where visitors came from, and which sources turn into orders. */
    public function sources(): Collection
    {
        $rows = $this->events()->selectRaw("source, count(distinct visitor) as visitors, count(distinct case when type = 'order' then visitor end) as buyers, sum(case when type = 'order' then value else 0 end) as revenue")
            ->groupBy('source')->orderByDesc('visitors')->limit(12)->get();

        return $rows->map(fn ($r) => (object) ['source' => $r->source, 'visitors' => (int) $r->visitors, 'buyers' => (int) $r->buyers, 'revenue' => (float) $r->revenue,
            'conversion' => $r->visitors ? 100 * $r->buyers / $r->visitors : 0]);
    }

    public function devices(): Collection
    {
        return $this->events()->selectRaw('device, count(distinct visitor) as n')->groupBy('device')->pluck('n', 'device');
    }

    public function topPages(): Collection
    {
        return $this->events()->whereIn('type', ['view', 'product'])->selectRaw('path, count(*) as views, count(distinct visitor) as visitors')
            ->groupBy('path')->orderByDesc('views')->limit(12)->get();
    }

    /** Products by views, with add-to-cart and order counts, so weak product pages stand out. */
    public function products(): Collection
    {
        $views = $this->events()->where('type', 'product')->whereNotNull('product_id')->selectRaw('product_id, count(*) as views')->groupBy('product_id')->pluck('views', 'product_id');
        $carts = $this->events()->where('type', 'cart')->whereNotNull('product_id')->selectRaw('product_id, count(*) as n')->groupBy('product_id')->pluck('n', 'product_id');
        $sold = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.woo_id')->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->selectRaw('order_items.product_id, sum(order_items.qty) as qty, sum(order_items.line_total) as revenue')->groupBy('order_items.product_id')->get()->keyBy('product_id');
        $ids = collect($views->keys())->merge($sold->keys())->unique()->filter();
        $products = Product::whereIn('id', $ids)->get(['id', 'name', 'slug'])->keyBy('id');

        return $ids->map(fn ($id) => (object) [
            'product' => $products[$id] ?? null,
            'views' => (int) ($views[$id] ?? 0),
            'carts' => (int) ($carts[$id] ?? 0),
            'sold' => (int) ($sold[$id]->qty ?? 0),
            'revenue' => (float) ($sold[$id]->revenue ?? 0),
        ])->filter(fn ($r) => $r->product)->sortByDesc(fn ($r) => [$r->revenue, $r->views])->values()->take(15);
    }
}

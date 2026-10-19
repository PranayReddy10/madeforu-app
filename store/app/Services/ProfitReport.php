<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Admin → Profit: what the shop kept after making, packing, courier and payment fees.
 * Costs come from the order line (saved at checkout) or, for older orders, the product's cost today.
 */
class ProfitReport
{
    public Carbon $from;

    /** @var Collection<int, Order> */
    private Collection $orders;

    /** @var Collection<int, Order> */
    private Collection $returned;

    public function __construct(public int $days = 30)
    {
        $this->from = today()->subDays($days - 1)->startOfDay();
        $base = fn () => Order::with('items.product', 'items.variant')->where('created_at', '>=', $this->from);
        $this->orders = $base()->whereNotIn('status', ['cancelled', 'returned', 'pending'])
            ->where(fn ($q) => $q->where('payment_status', 'paid')->orWhere('payment_method', 'cod'))->get();
        $this->returned = $base()->where('status', 'returned')->get();
    }

    private static function lineCost($item): ?float
    {
        $unit = $item->unit_cost ?? $item->product?->costFor($item->variant);

        return $unit !== null ? round((float) $unit * $item->qty, 2) : null;
    }

    private static function courier(Order $o): float
    {
        $fixed = (float) setting('profit.shipping_fixed');

        return setting('profit.shipping_mode', 'charged') === 'charged' && (float) $o->shipping > 0 ? (float) $o->shipping : $fixed;
    }

    private static function gatewayFee(Order $o): float
    {
        return $o->payment_method === 'cod' ? 0.0 : round((float) $o->total * (float) setting('profit.gateway_percent') / 100, 2);
    }

    /** Totals for the period. */
    public function summary(): array
    {
        $revenue = (float) $this->orders->sum('total');
        $making = 0.0;
        $missing = 0;
        foreach ($this->orders as $o) {
            foreach ($o->items as $i) {
                $c = self::lineCost($i);
                $c === null ? $missing++ : $making += $c;
            }
        }
        $packing = $this->orders->count() * (float) setting('profit.packing');
        $courier = (float) $this->orders->sum(fn ($o) => self::courier($o));
        $fees = (float) $this->orders->sum(fn ($o) => self::gatewayFee($o));
        // A returned parcel: made, packed and sent both ways, but no money in.
        $returns = (float) $this->returned->sum(fn ($o) => $o->items->sum(fn ($i) => self::lineCost($i) ?? 0) + (float) setting('profit.packing') + 2 * self::courier($o));
        $profit = $revenue - $making - $packing - $courier - $fees - $returns;

        return [
            'orders' => $this->orders->count(), 'revenue' => $revenue, 'making' => $making, 'packing' => $packing,
            'courier' => $courier, 'fees' => $fees, 'returns' => $returns, 'returned' => $this->returned->count(),
            'profit' => $profit, 'margin' => $revenue > 0 ? 100 * $profit / $revenue : 0,
            'per_order' => $this->orders->count() ? $profit / $this->orders->count() : 0, 'missing' => $missing,
        ];
    }

    /** Per product: units, sales, making cost, gross profit — best earners first, products without a cost flagged. */
    public function products(): Collection
    {
        return $this->orders->flatMap->items->groupBy(fn ($i) => $i->product_id ?: 'x'.$i->name)->map(function ($items) {
            $first = $items->first();
            $costs = $items->map(fn ($i) => self::lineCost($i));
            $sales = (float) $items->sum('line_total');
            $known = $costs->every(fn ($c) => $c !== null);
            $cost = (float) $costs->sum();

            return (object) [
                'product' => $first->product, 'name' => $first->product?->name ?? $first->name,
                'units' => (int) $items->sum('qty'), 'sales' => $sales,
                'cost' => $known ? $cost : null, 'profit' => $known ? $sales - $cost : null,
                'margin' => $known && $sales > 0 ? 100 * ($sales - $cost) / $sales : null,
            ];
        })->sortByDesc(fn ($r) => [$r->profit ?? -INF, $r->sales])->values();
    }
}

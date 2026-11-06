<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Admin → Sales, for the accounts: an order becomes a sale when it is delivered (dated by its
 * delivery day). Split by online payment and cash on delivery, with the COD cash Delhivery still
 * has to pay into the bank, money still on the way, returns, cancellations and GST.
 */
class SalesReport
{
    /** The day an order counts as sold. Old imported orders may have no delivery date. */
    public const SALE_DATE = 'COALESCE(delivered_at, shipped_at, created_at)';

    public const PERIODS = ['today' => 'Today', '7d' => '7 days', 'month' => 'This month', 'last_month' => 'Last month', 'fy' => 'This financial year', 'custom' => 'Custom'];

    public Carbon $from;

    public Carbon $to;

    public function __construct(public string $period = 'month', ?string $from = null, ?string $to = null)
    {
        $now = now();
        [$this->from, $this->to] = match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            // Indian financial year: 1 April – 31 March.
            'fy' => [Carbon::create($now->month >= 4 ? $now->year : $now->year - 1, 4, 1)->startOfDay(), $now->copy()->endOfDay()],
            'custom' => [self::date($from, $now->copy()->startOfMonth())->startOfDay(), self::date($to, $now)->endOfDay()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };
        if ($this->from->gt($this->to)) {
            [$this->from, $this->to] = [$this->to->copy()->startOfDay(), $this->from->copy()->endOfDay()];
        }
    }

    private static function date(?string $d, Carbon $fallback): Carbon
    {
        try {
            return $d ? Carbon::parse($d) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** Delivered orders in the period. */
    public function sales(?string $method = null): Builder
    {
        return Order::where('status', 'delivered')
            ->whereRaw(self::SALE_DATE.' between ? and ?', [$this->from, $this->to])
            ->when($method === 'cod', fn ($q) => $q->where('payment_method', 'cod'))
            ->when($method === 'online', fn ($q) => $q->where('payment_method', '!=', 'cod'))
            ->orderByRaw(self::SALE_DATE.' desc');
    }

    /** Delivered COD orders whose cash Delhivery hasn't paid in yet — any date. */
    public static function codDue(): Builder
    {
        return Order::where('status', 'delivered')->where('payment_method', 'cod')->whereNull('cod_remitted_at')->orderByRaw(self::SALE_DATE);
    }

    public function summary(): array
    {
        $sum = fn (Builder $q) => ['value' => round((float) (clone $q)->sum('total'), 2), 'count' => (clone $q)->count()];
        $open = Order::whereIn('status', ['confirmed', 'in_production', 'shipped']);
        $changedInPeriod = fn (string $status) => Order::where('status', $status)->whereBetween('updated_at', [$this->from, $this->to]);

        return [
            'sales' => $sum($this->sales()),
            'online' => $sum($this->sales('online')),
            'cod' => $sum($this->sales('cod')),
            'cod_received' => $sum($this->sales('cod')->whereNotNull('cod_remitted_at')),
            'cod_due' => $sum(self::codDue()),
            'open_online' => $sum((clone $open)->where('payment_method', '!=', 'cod')->where('payment_status', 'paid')),
            'open_cod' => $sum((clone $open)->where('payment_method', 'cod')),
            'awaiting_payment' => $sum(Order::where('status', 'pending')->where('created_at', '>=', now()->subDays(7))),
            'returned' => $sum($changedInPeriod('returned')),
            'cancelled' => $sum($changedInPeriod('cancelled')),
            'refunded' => $sum(Order::where('payment_status', 'refunded')->whereBetween('updated_at', [$this->from, $this->to])),
        ];
    }

    /**
     * One row per delivered order, with the GST split from its invoice.
     *
     * @return Collection<int, array>
     */
    public function rows(?string $method = null): Collection
    {
        return $this->sales($method)->with('items.product')->get()->map(function (Order $o) {
            $b = Invoice::breakdown($o);

            return [
                'order' => $o,
                'date' => Carbon::parse($o->delivered_at ?? $o->shipped_at ?? $o->created_at),
                'method' => $o->payment_method === 'cod' ? 'COD' : 'Online ('.$o->paymentLabel().')',
                'taxable' => $b['taxable'],
                'gst' => $b['tax'],
                'total' => (float) $o->total,
            ];
        });
    }
}

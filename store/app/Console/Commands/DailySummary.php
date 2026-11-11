<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Services\OrderEmails;
use App\Services\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class DailySummary extends Command
{
    protected $signature = 'store:daily-summary {--force : Send even if switched off}';

    protected $description = 'Email the shop a summary of today and what needs doing';

    public function handle(): int
    {
        if (! $this->option('force') && ! setting('notify.admin_daily_summary', true)) {
            return self::SUCCESS;
        }
        $confirmed = fn () => Order::whereNull('woo_id')->whereNotIn('status', ['pending', 'cancelled']);
        $today = $confirmed()->where('created_at', '>=', today());
        $low = Stock::lowThreshold();
        $s = [
            'orders_today' => (clone $today)->count(),
            'revenue_today' => (float) (clone $today)->sum('total'),
            'cod_today' => (clone $today)->where('payment_method', 'cod')->count(),
            'month_revenue' => (float) $confirmed()->where('created_at', '>=', now()->startOfMonth())->sum('total'),
            'to_make' => $confirmed()->whereIn('status', ['confirmed', 'in_production'])->orderBy('created_at')->get(),
            'to_hand_over' => $confirmed()->whereIn('status', ['confirmed', 'in_production'])->whereNotNull('tracking_number')->count(),
            'unpaid_online' => Order::whereNull('woo_id')->where('status', 'pending')->where('payment_method', '!=', 'cod')
                ->where('created_at', '>=', now()->subDay())->get(),
            'shipping_problems' => $confirmed()->whereNotNull('shipment_error')->whereNull('tracking_number')->get(),
            'low_stock' => Product::whereNotNull('stock_qty')->where('type', 'simple')->where('stock_qty', '<=', $low)->get(['name', 'stock_qty'])
                ->map(fn ($p) => [$p->name, $p->stock_qty])
                ->concat(ProductVariant::with('product')->whereNotNull('stock_qty')->where('stock_qty', '<=', $low)->get()
                    ->map(fn ($v) => [($v->product?->name ?? '').' — '.$v->label(), $v->stock_qty])),
            'reviews_waiting' => Review::where('is_approved', false)->count(),
        ];
        if ($to = OrderEmails::adminAddresses()) {
            Mail::to($to)->send(new \App\Mail\DailySummary($s));
        }
        $this->info("Summary sent: {$s['orders_today']} orders today.");

        return self::SUCCESS;
    }
}

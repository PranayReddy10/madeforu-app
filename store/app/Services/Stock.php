<?php

namespace App\Services;

use App\Mail\LowStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Stock counts. A blank count means made to order (never runs out). Variable products count per
 * variation; simple products on the product. Stock goes down when an order is confirmed and comes
 * back when it is cancelled or returned.
 */
class Stock
{
    /** How many can still be bought; null = no limit. */
    public static function available(Product $product, ?ProductVariant $variant = null): ?int
    {
        $qty = $variant ? $variant->stock_qty : ($product->type === 'variable' ? null : $product->stock_qty);

        return $qty === null ? null : max(0, (int) $qty);
    }

    public static function lowThreshold(): int
    {
        return max(0, (int) setting('low_stock_threshold', 3));
    }

    public function reduce(Order $order): void
    {
        $this->move($order, -1, 'order');
    }

    public function restore(Order $order, string $reason = 'cancelled'): void
    {
        $this->move($order, 1, $reason);
    }

    private function move(Order $order, int $sign, string $reason): void
    {
        $low = [];
        DB::transaction(function () use ($order, $sign, $reason, &$low) {
            $o = Order::whereKey($order->id)->lockForUpdate()->first();
            // Reduce once; restore only what was reduced.
            if ($sign < 0 ? $o->stock_reduced : ! $o->stock_reduced) {
                return;
            }
            foreach ($o->items as $item) {
                $model = $item->variant_id ? ProductVariant::whereKey($item->variant_id)->lockForUpdate()->first()
                    : ($item->product_id ? Product::whereKey($item->product_id)->lockForUpdate()->first() : null);
                if (! $model || $model->stock_qty === null) {
                    continue;
                }
                $before = (int) $model->stock_qty;
                $after = max(0, $before + $sign * $item->qty);
                $model->forceFill(['stock_qty' => $after, 'in_stock' => $after > 0])->saveQuietly();
                StockMovement::create([
                    'product_id' => $item->product_id, 'variant_id' => $item->variant_id, 'order_id' => $o->id,
                    'change' => $after - $before, 'balance' => $after, 'reason' => $reason,
                    'note' => $sign < 0 && $before < $item->qty ? 'Ordered '.$item->qty.' with only '.$before.' in stock' : null,
                ]);
                if ($sign < 0 && $before > self::lowThreshold() && $after <= self::lowThreshold()) {
                    $low[] = ['name' => $item->name.($item->options ? ' ('.implode(' / ', $item->options).')' : ''), 'qty' => $after];
                }
            }
            $o->forceFill(['stock_reduced' => $sign < 0])->saveQuietly();
            $order->forceFill(['stock_reduced' => $sign < 0]);
        });
        Product::flushMenuCache();

        if ($low && setting('notify.admin_low_stock', true) && ($to = OrderEmails::adminAddresses())) {
            try {
                Mail::to($to)->send(new LowStock($low));
            } catch (\Throwable $e) {
                Log::warning('Low stock mail failed: '.$e->getMessage());
            }
        }
    }

    /** Record a change made by hand in admin. */
    public static function logAdjustment(Product $product, ?ProductVariant $variant, ?int $before, ?int $after): void
    {
        if ($before === $after || $after === null) {
            return;
        }
        StockMovement::create([
            'product_id' => $product->id, 'variant_id' => $variant?->id, 'change' => $after - (int) $before,
            'balance' => $after, 'reason' => 'adjustment', 'note' => $before === null ? 'Stock counting started' : null,
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbandonedCart extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['lines' => 'array', 'last_reminded_at' => 'datetime', 'recovered_at' => 'datetime', 'unsubscribed_at' => 'datetime'];

    public function recoveredOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'recovered_order_id');
    }

    public function recoverUrl(): string
    {
        return route('cart.recover', $this->token);
    }

    /** Products in the cart, for the reminder: [{product, variant, qty}] (gone products dropped). */
    public function items(): array
    {
        $lines = collect($this->lines);
        $products = Product::active()->with(['images', 'variants'])->whereIn('id', $lines->pluck('product_id'))->get()->keyBy('id');

        return $lines->map(function ($l) use ($products) {
            $p = $products->get($l['product_id']);

            return $p ? ['product' => $p, 'variant' => $l['variant_id'] ? $p->variants->firstWhere('id', $l['variant_id']) : null, 'qty' => (int) $l['qty']] : null;
        })->filter()->values()->all();
    }
}

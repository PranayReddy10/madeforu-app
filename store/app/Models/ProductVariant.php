<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['options' => 'array', 'in_stock' => 'boolean', 'stock_qty' => 'integer', 'price' => 'decimal:2', 'sale_price' => 'decimal:2'];

    protected static function booted(): void
    {
        static::saving(function (ProductVariant $v) {
            if ($v->stock_qty !== null) {
                $v->in_stock = $v->stock_qty > 0;
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function currentPrice(): float
    {
        $sale = (float) $this->sale_price;

        return $sale > 0 && $sale < (float) $this->price ? $sale : (float) $this->price;
    }

    public function label(): string
    {
        return collect($this->options)->map(fn ($v, $k) => "$k: $v")->implode(', ');
    }
}

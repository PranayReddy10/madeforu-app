<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['options' => 'array', 'customization' => 'array'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** The SKU saved with the order; older or imported orders fall back to the product's current one. */
    public function skuCode(): ?string
    {
        return $this->sku ?: ($this->variant_id ? $this->variant?->sku : null) ?: $this->product?->sku;
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(CustomerUpload::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'is_active' => 'boolean'];

    /** Returns the discount for a subtotal, or an error message string when the coupon cannot be used. */
    public function discountFor(float $subtotal): float|string
    {
        if (! $this->is_active || ($this->starts_at && $this->starts_at->isFuture())) {
            return 'This coupon is not active.';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'This coupon has expired.';
        }
        if ($this->usage_limit !== null && $this->used >= $this->usage_limit) {
            return 'This coupon has been fully used.';
        }
        if ($subtotal < (float) $this->min_subtotal) {
            return 'Add items worth ₹'.number_format($this->min_subtotal).' to use this coupon.';
        }
        $d = $this->type === 'percent' ? $subtotal * (float) $this->value / 100 : (float) $this->value;
        if ($this->max_discount !== null) {
            $d = min($d, (float) $this->max_discount);
        }

        return round(min($d, $subtotal), 2);
    }
}

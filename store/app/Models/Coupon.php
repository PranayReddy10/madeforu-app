<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'is_active' => 'boolean', 'show_on_cart' => 'boolean'];

    /**
     * Why this coupon can't be used on a subtotal right now, or null when it can.
     * 'min' is the only reason that can fix itself (add more to the cart).
     *
     * @return array{reason: string, message: string, short?: float}|null
     */
    public function problem(float $subtotal): ?array
    {
        $code = strtoupper($this->code);
        if (! $this->is_active) {
            return ['reason' => 'inactive', 'message' => "{$code} isn’t active right now."];
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return ['reason' => 'not_started', 'message' => "{$code} starts on {$this->starts_at->format('j M')} — use it from then."];
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return ['reason' => 'expired', 'message' => "{$code} expired on {$this->expires_at->format('j M Y')}."];
        }
        if ($this->usage_limit !== null && $this->used >= $this->usage_limit) {
            return ['reason' => 'used_up', 'message' => "{$code} has been fully used — no uses left."];
        }
        $min = (float) $this->min_subtotal;
        if ($subtotal < $min) {
            $short = round($min - $subtotal, 2);

            return ['reason' => 'min', 'short' => $short,
                'message' => "{$code} works on orders of ".money($min).' or more — add '.money($short).' more to use it.'];
        }

        return null;
    }

    /** Returns the discount for a subtotal, or an error message string when the coupon cannot be used. */
    public function discountFor(float $subtotal): float|string
    {
        if ($p = $this->problem($subtotal)) {
            return $p['message'];
        }
        $d = $this->type === 'percent' ? $subtotal * (float) $this->value / 100 : (float) $this->value;
        if ($this->max_discount !== null) {
            $d = min($d, (float) $this->max_discount);
        }

        return round(min($d, $subtotal), 2);
    }

    /** "10% off on orders of ₹499+ · up to ₹100 off" for the cart's offer list. */
    public function offerText(): string
    {
        return $this->offText()
            .((float) $this->min_subtotal > 0 ? ' on orders of '.money($this->min_subtotal).'+' : ' on your order')
            .($this->type === 'percent' && $this->max_discount !== null ? ' · up to '.money($this->max_discount).' off' : '');
    }

    /** "10% off" / "₹100 off" */
    public function offText(): string
    {
        return $this->type === 'percent' ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off' : money($this->value).' off';
    }
}

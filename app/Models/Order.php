<?php

namespace App\Models;

use App\Services\OrderEmails;
use App\Services\Parcel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['review_requested_at' => 'datetime', 'utm' => 'array', 'shipment_scans' => 'array', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'tracked_at' => 'datetime', 'invoiced_at' => 'datetime'];

    public const STATUSES = [
        'pending' => 'Awaiting payment',
        'confirmed' => 'Confirmed',
        'in_production' => 'Being made',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'returned' => 'Returned',
        'cancelled' => 'Cancelled',
    ];

    protected static function booted(): void
    {
        // One place that notices every status change — admin, courier tracking or payment — and emails about it.
        static::updated(function (Order $order) {
            $emails = app(OrderEmails::class);
            if ($order->wasChanged('status')) {
                $emails->statusChanged($order, $order->getOriginal('status'), $order->status);
            }
            if ($order->wasChanged('payment_status') && $order->payment_status === 'refunded') {
                $emails->refunded($order);
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trackingUrl(): ?string
    {
        return $this->tracking_number ? 'https://www.delhivery.com/track/package/'.rawurlencode($this->tracking_number) : null;
    }

    /** Grams to declare to the courier: product weights where set, otherwise the default per item. */
    public function shippingWeight(): int
    {
        if ($this->weight_grams) {
            return $this->weight_grams;
        }
        $this->loadMissing('items.product');

        return max(50, (int) $this->items->sum(fn ($i) => $i->qty * Parcel::itemGrams($i->product)));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public static function nextNumber(): string
    {
        $prefix = 'MFU'.now()->format('ymd');
        $last = static::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}

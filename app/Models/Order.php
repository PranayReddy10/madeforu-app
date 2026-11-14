<?php

namespace App\Models;

use App\Services\OrderEmails;
use App\Services\Parcel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['review_requested_at' => 'datetime', 'utm' => 'array', 'shipment_scans' => 'array', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'tracked_at' => 'datetime', 'invoiced_at' => 'datetime', 'cod_remitted_at' => 'datetime', 'sale_synced_at' => 'datetime'];

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
            // Delivered orders go to the sale panel's accounts; out again if it comes back.
            if ($order->wasChanged('status') && \App\Services\SalePanel::enabled() && $order->sale_order_no !== \App\Services\SalePanel::MANUAL) {
                if ($order->status === 'delivered') {
                    \App\Jobs\SendToSalePanel::dispatchAfterResponse($order->id);
                } elseif ($order->getOriginal('status') === 'delivered' && $order->sale_synced_at) {
                    \App\Jobs\SendToSalePanel::dispatchAfterResponse($order->id, 'remove');
                }
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

    /** "Razorpay", "UPI", "Bank transfer", "COD" (short) / "Cash on delivery". */
    public function paymentLabel(bool $short = false): string
    {
        return match ($this->payment_method) {
            'cod' => $short ? 'COD' : 'Cash on delivery',
            'upi' => 'UPI',
            'bank' => 'Bank transfer',
            default => ucfirst((string) $this->payment_method),
        };
    }

    /** What paying online costs: the total, less the cash-on-delivery charge on an unpaid COD order. */
    public function payableOnline(): float
    {
        return round((float) $this->total - ($this->payment_method === 'cod' ? (float) $this->cod_fee : 0), 2);
    }

    /** Why a payment link can't be offered for this order right now, or null when it can. */
    public function payLinkProblem(): ?string
    {
        return match (true) {
            $this->payment_status === 'paid' => 'Already paid.',
            in_array($this->status, ['cancelled', 'returned', 'shipped', 'delivered'], true) => 'The order is '.strtolower($this->statusLabel()).'.',
            ! \App\Http\Controllers\CheckoutController::gateways() => 'Switch on Razorpay or Cashfree in Settings to send payment links.',
            $this->payment_method === 'cod' && (bool) $this->tracking_number => 'Booked with '.($this->courier ?: 'the courier').' as cash on delivery (AWB '.$this->tracking_number.'). Cancel that shipment first, then send a payment link.',
            $this->payableOnline() <= 0 => 'Nothing to pay.',
            default => null,
        };
    }

    /** Paid through a payment gateway (and so charged its fee). */
    public function viaGateway(): bool
    {
        return in_array($this->payment_method, ['razorpay', 'cashfree'], true);
    }

    /**
     * Next order number: MFU + year + a counter from 1001, e.g. MFU20261001, MFU20261002 … (MFU20271001 next year).
     * Older orders keep their numbers (MFU261114001, WC-22511); they never start with MFU + a 4-digit year.
     */
    public static function nextNumber(): string
    {
        $prefix = 'MFU'.now()->format('Y');
        $n = static::where('number', 'like', $prefix.'%')->pluck('number')
            ->map(fn ($no) => substr($no, strlen($prefix)))
            ->filter(fn ($rest) => $rest !== '' && ctype_digit($rest))
            ->map(fn ($rest) => (int) $rest)
            ->max();

        return $prefix.max(1001, ($n ?? 1000) + 1);
    }

    /** Create an order with the next number; if two orders take the same number at once, the second tries again. */
    public static function createNumbered(array $attributes): self
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => static::create(['number' => static::nextNumber()] + $attributes));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}

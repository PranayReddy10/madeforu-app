<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Keeps an order and its Delhivery shipment in step. */
class Shipping
{
    public function __construct(private Delhivery $delhivery, private OrderNotifier $notifier) {}

    /** Create the Delhivery shipment once. Errors are kept on the order for admin to see and retry. */
    public function ship(Order $order): bool
    {
        if ($order->tracking_number || ! Delhivery::enabled() || in_array($order->status, ['pending', 'cancelled'], true)) {
            return false;
        }
        try {
            $awb = $this->delhivery->createShipment($order);
        } catch (\Throwable $e) {
            Log::warning("Delhivery shipment for {$order->number}: ".$e->getMessage());
            $order->update(['shipment_error' => $e->getMessage()]);
            app(OrderEmails::class)->shipmentFailed($order, $e->getMessage());

            return false;
        }
        $order->update(['tracking_number' => $awb, 'courier' => 'Delhivery', 'shipment_status' => 'Manifested', 'shipment_error' => null]);

        return true;
    }

    /** Pull the latest tracking and move the order along: shipped, delivered, returned. */
    public function sync(Order $order, ?array $t = null): void
    {
        if (! $order->tracking_number || ! Delhivery::enabled()) {
            return;
        }
        $t ??= $this->delhivery->track($order->tracking_number);
        Cache::put('track.live.'.$order->tracking_number, $t, now()->addMinutes(10));
        $changes = ['shipment_status' => $t['status'], 'shipment_scans' => $t['scans'], 'tracked_at' => now()];
        $status = strtolower((string) $t['status']);
        $wasShipped = in_array($order->status, ['shipped', 'delivered', 'returned'], true);

        if ($t['type'] === 'DL' || $status === 'delivered') {
            $changes += ['status' => 'delivered', 'delivered_at' => $order->delivered_at ?? now(), 'shipped_at' => $order->shipped_at ?? now()];
            if ($order->payment_method === 'cod') {
                $changes['payment_status'] = 'paid'; // Delhivery collected the cash
            }
        } elseif ($t['type'] === 'RT' || str_contains($status, 'rto') || str_contains($status, 'return')) {
            $changes['status'] = 'returned';
        } elseif ($t['type'] === 'UD' && ! in_array($status, ['manifested', 'not picked', 'pending pickup'], true)) {
            // Picked up and moving
            $changes += ['status' => 'shipped', 'shipped_at' => $order->shipped_at ?? now()];
        }
        $order->update($changes);
        if (($changes['status'] ?? null) === 'returned') {
            app(Stock::class)->restore($order, 'returned');
        }

        if (! $wasShipped && in_array($order->status, ['shipped', 'delivered'], true)) {
            $this->notifyShipped($order);
        }
        if ($order->status === 'delivered' && ($changes['status'] ?? null) === 'delivered') {
            $this->notifier->delivered($order);
        }
    }

    /**
     * Live Delhivery tracking for the customer's Track order page: asked at most every 10 minutes per parcel,
     * and the order is moved along (shipped / delivered / returned) on the way. Null when there is nothing live
     * to show — the page then uses the updates stored by the background sync.
     *
     * @return array{status: ?string, type: ?string, at: ?string, location: ?string, expected: ?string, scans: array}|null
     */
    public function live(Order $order): ?array
    {
        if (! $order->tracking_number || ! Delhivery::enabled() || ($order->courier && $order->courier !== 'Delhivery')) {
            return null;
        }
        $key = 'track.live.'.$order->tracking_number;
        if (Cache::has($key)) {
            return Cache::get($key) ?: null;
        }
        // A parcel that arrived or came back no longer changes.
        if (in_array($order->status, ['delivered', 'returned', 'cancelled'], true) && $order->shipment_scans) {
            return null;
        }
        try {
            $t = $this->delhivery->track($order->tracking_number);
            $this->sync($order, $t);

            return $t;
        } catch (\Throwable $e) {
            Log::info("Live tracking {$order->number}: ".$e->getMessage());
            Cache::put($key, false, now()->addMinutes(5));   // don't ask again on every refresh

            return null;
        }
    }

    /**
     * The parcel's state in the customer's words, and which step it has reached.
     *
     * @return array{label: string, step: int, tone: string}  step: 1 confirmed, 2 being made, 3 shipped, 4 out for delivery, 5 delivered
     */
    public static function describe(Order $order, ?string $courierStatus): array
    {
        $s = strtolower((string) ($courierStatus ?? $order->shipment_status));
        return match (true) {
            $order->status === 'delivered' || $s === 'delivered' => ['label' => 'Delivered', 'step' => 5, 'tone' => 'ok'],
            $order->status === 'returned' || str_contains($s, 'rto') || str_contains($s, 'return') => ['label' => 'Being returned to us', 'step' => 3, 'tone' => 'warn'],
            $order->status === 'cancelled' => ['label' => 'Cancelled', 'step' => 0, 'tone' => 'warn'],
            str_contains($s, 'dispatch') || str_contains($s, 'out for delivery') => ['label' => 'Out for delivery today', 'step' => 4, 'tone' => 'go'],
            $order->status === 'shipped' || str_contains($s, 'transit') || $s === 'pending' => ['label' => 'On the way', 'step' => 3, 'tone' => 'go'],
            $order->tracking_number && in_array($s, ['manifested', 'not picked', 'pending pickup', ''], true) => ['label' => 'Packed — waiting for courier pickup', 'step' => 2, 'tone' => 'wait'],
            $order->status === 'in_production' => ['label' => 'Being made', 'step' => 2, 'tone' => 'wait'],
            $order->status === 'pending' => ['label' => 'Awaiting payment', 'step' => 0, 'tone' => 'warn'],
            default => ['label' => 'Order confirmed', 'step' => 1, 'tone' => 'wait'],
        };
    }

    public function notifyShipped(Order $order): void
    {
        // The "shipped" email goes out from Order's status watcher; this adds WhatsApp.
        $this->notifier->shipped($order);
    }
}

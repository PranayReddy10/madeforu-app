<?php

namespace App\Services;

use App\Mail\OrderShipped;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

            return false;
        }
        $order->update(['tracking_number' => $awb, 'courier' => 'Delhivery', 'shipment_status' => 'Manifested', 'shipment_error' => null]);

        return true;
    }

    /** Pull the latest tracking and move the order along: shipped, delivered, returned. */
    public function sync(Order $order): void
    {
        if (! $order->tracking_number || ! Delhivery::enabled()) {
            return;
        }
        $t = $this->delhivery->track($order->tracking_number);
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

    public function notifyShipped(Order $order): void
    {
        try {
            Mail::to($order->email)->send(new OrderShipped($order));
        } catch (\Throwable $e) {
            Log::warning('Shipped mail failed: '.$e->getMessage());
        }
        $this->notifier->shipped($order);
    }
}

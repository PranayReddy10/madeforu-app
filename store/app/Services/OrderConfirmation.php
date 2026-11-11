<?php

namespace App\Services;

use App\Jobs\CreateShipment;
use App\Jobs\NotifyOrder;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;

/**
 * Everything that happens once an order is confirmed: COD placed, online payment received,
 * or the shop switching a failed-payment order to cash on delivery.
 */
class OrderConfirmation
{
    public static function run(Order $order, bool $notify = true): void
    {
        if ($order->coupon_code) {
            Coupon::where('code', $order->coupon_code)->increment('used');
        }
        foreach ($order->items as $i) {
            Product::whereKey($i->product_id)->increment('sales_count', $i->qty);
        }
        app(Stock::class)->reduce($order);
        app(CartRecovery::class)->markRecovered($order);
        Invoice::assign($order);
        if ($notify) {
            // WhatsApp to the customer and the shop, after the page has gone out.
            NotifyOrder::dispatchAfterResponse($order->id, 'confirmed');
        }
        // Book the Delhivery shipment right away (after the page has gone out).
        if (Delhivery::enabled() && setting('delhivery.auto')) {
            CreateShipment::dispatchAfterResponse($order->id);
        }
        if ($notify) {
            app(OrderEmails::class)->placed($order);
        }
    }
}

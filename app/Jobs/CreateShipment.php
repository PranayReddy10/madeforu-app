<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Shipping;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Books the Delhivery shipment for a confirmed order. Runs after the customer's page has been sent. */
class CreateShipment implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId) {}

    public function handle(Shipping $shipping): void
    {
        if ($order = Order::find($this->orderId)) {
            $shipping->ship($order);
        }
    }
}

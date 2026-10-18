<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends an order step's WhatsApp messages after the page has gone out. */
class NotifyOrder implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId, public string $event) {}

    public function handle(OrderNotifier $notifier): void
    {
        $order = Order::with('items')->find($this->orderId);
        if ($order && in_array($this->event, ['confirmed', 'shipped', 'delivered'], true)) {
            $notifier->{$this->event}($order);
        }
    }
}

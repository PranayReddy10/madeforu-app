<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\SalePanel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Adds a delivered order to the sale panel (or takes it out again) after the page has gone out. */
class SendToSalePanel implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId, public string $action = 'upsert') {}

    public function handle(): void
    {
        $order = Order::find($this->orderId);
        if (! $order || ! SalePanel::enabled()) {
            return;
        }
        $this->action === 'remove' ? SalePanel::remove($order) : SalePanel::push($order);
    }
}

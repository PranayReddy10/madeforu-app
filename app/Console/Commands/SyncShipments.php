<?php

namespace App\Console\Commands;

use App\Jobs\CreateShipment;
use App\Models\Order;
use App\Services\Delhivery;
use App\Services\Shipping;
use Illuminate\Console\Command;

class SyncShipments extends Command
{
    protected $signature = 'store:delhivery-sync';

    protected $description = 'Update Delhivery tracking for open shipments, and book shipments that failed or were missed';

    public function handle(Shipping $shipping): int
    {
        if (! Delhivery::enabled()) {
            $this->line('Delhivery is not set up; nothing to do.');

            return self::SUCCESS;
        }
        $open = Order::whereNotNull('tracking_number')->where('courier', 'Delhivery')
            ->whereNotIn('status', ['delivered', 'returned', 'cancelled'])->get();
        foreach ($open as $order) {
            try {
                $shipping->sync($order);
                $this->line("{$order->number}: {$order->fresh()->shipment_status}");
            } catch (\Throwable $e) {
                $this->warn("{$order->number}: ".$e->getMessage());
            }
        }

        // Automatic booking: retry confirmed orders from the last week that still have no AWB.
        if (setting('delhivery.auto')) {
            Order::whereNull('tracking_number')->whereIn('status', ['confirmed', 'in_production'])
                ->where('created_at', '>=', now()->subDays(7))->pluck('id')
                ->each(fn ($id) => CreateShipment::dispatchSync($id));
        }

        return self::SUCCESS;
    }
}

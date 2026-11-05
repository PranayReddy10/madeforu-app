<?php

namespace App\Services;

use App\Mail\AdminAlert;
use App\Mail\OrderPlaced;
use App\Mail\OrderShipped;
use App\Mail\OrderUpdate;
use App\Models\NotificationLog;
use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Every email about an order goes through here: one switch per email in Settings, sent once per
 * order and step, logged on the order. A mail failure never breaks the order flow.
 */
class OrderEmails
{
    /** Customer email per order status (status => setting key). */
    private const CUSTOMER_STATUS = [
        'in_production' => 'notify.customer_in_production',
        'shipped' => 'notify.customer_shipped',
        'delivered' => 'notify.customer_delivered',
        'cancelled' => 'notify.customer_cancelled',
        'returned' => 'notify.customer_returned',
    ];

    /** @return array<int, string> */
    public static function adminAddresses(): array
    {
        return collect(explode(',', (string) (setting('notify.admin_emails') ?: config('store.email'))))
            ->map(fn ($e) => trim($e))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
    }

    /** Order confirmed (COD placed or payment received): customer confirmation with invoice, and the shop's copy. */
    public function placed(Order $order): void
    {
        $this->toCustomer($order, 'placed', new OrderPlaced($order), true);
        if (setting('notify.admin_new_order', true)) {
            $this->toAdmins($order, 'admin_new_order', new OrderPlaced($order, true));
        }
    }

    /** Called whenever an order's status changes, wherever that happens (admin, courier tracking, payment). */
    public function statusChanged(Order $order, ?string $from, string $to): void
    {
        if ($order->woo_id || $from === $to) {
            return;   // imported history never emails anyone
        }
        if (isset(self::CUSTOMER_STATUS[$to]) && setting(self::CUSTOMER_STATUS[$to], true)) {
            $mail = $to === 'shipped' ? new OrderShipped($order) : new OrderUpdate($order, $to);
            $this->toCustomer($order, $to, $mail);
        }
        if ($to === 'returned' && setting('notify.admin_shipping', true)) {
            $this->toAdmins($order, 'admin_returned', new AdminAlert(
                'Parcel returned: order '.$order->number,
                ['The courier returned order <b>'.e($order->number).'</b> ('.e($order->name).', '.e($order->city).'). '.e((string) $order->shipment_status),
                    'Contact the customer to arrange delivery again, then create a new shipment.'],
                route('admin.orders.show', $order),
            ));
        }
    }

    public function refunded(Order $order): void
    {
        if (! $order->woo_id && setting('notify.customer_refunded', true)) {
            $this->toCustomer($order, 'refunded', new OrderUpdate($order, 'refunded'));
        }
    }

    public function shipmentFailed(Order $order, string $reason): void
    {
        if (setting('notify.admin_shipping', true)) {
            $this->toAdmins($order, 'admin_shipment_failed', new AdminAlert(
                'Delhivery could not book order '.$order->number,
                ['Delhivery said: <b>'.e($reason).'</b>', 'Fix it (usually the pickup location name, PIN code or weight) and press Retry on the order.'],
                route('admin.orders.show', $order),
            ), once: false);
        }
    }

    private function toCustomer(Order $order, string $event, Mailable $mail, bool $force = false): void
    {
        if (! filter_var($order->email, FILTER_VALIDATE_EMAIL) || str_ends_with($order->email, '@madeforu.co.in')) {
            return;
        }
        if (! $force && $this->alreadySent($order, $event)) {
            return;
        }
        $this->send($order, $event, $order->email, $mail);
    }

    private function toAdmins(Order $order, string $event, Mailable $mail, bool $once = true): void
    {
        if ($once && $this->alreadySent($order, $event)) {
            return;
        }
        foreach (self::adminAddresses() as $to) {
            $this->send($order, $event, $to, clone $mail);
        }
    }

    private function alreadySent(Order $order, string $event): bool
    {
        return NotificationLog::where('order_id', $order->id)->where('channel', 'email')->where('event', $event)->where('status', 'sent')->exists();
    }

    private function send(Order $order, string $event, string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
            NotificationLog::create(['order_id' => $order->id, 'channel' => 'email', 'event' => $event, 'to' => $to, 'status' => 'sent']);
        } catch (\Throwable $e) {
            Log::warning("Email {$event} for {$order->number}: ".$e->getMessage());
            NotificationLog::create(['order_id' => $order->id, 'channel' => 'email', 'event' => $event, 'to' => $to, 'status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
        }
    }
}

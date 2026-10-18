<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Customer and shop-owner messages for each step of an order. Email is sent where the step happens;
 * this adds WhatsApp, and the ready-to-send texts for the admin's one-tap WhatsApp buttons.
 */
class OrderNotifier
{
    public function __construct(private WhatsApp $wa) {}

    public function confirmed(Order $order): void
    {
        $this->toCustomer($order, 'confirmed', setting('whatsapp_api.template_confirmed'), [
            self::firstName($order), $order->number, money($order->total), self::trackLink($order),
        ], setting('whatsapp_api.confirmed_invoice') ? ['link' => Invoice::publicUrl($order), 'filename' => Invoice::filename($order)] : null);

        if (self::live() && ($template = setting('whatsapp_api.template_admin'))) {
            foreach (array_filter(array_map(fn ($n) => WhatsApp::number(trim($n)), explode(',', (string) setting('whatsapp_api.admin_numbers')))) as $to) {
                $this->wa->sendForOrder($order, 'admin_alert', $to, $template, [
                    $order->number, $order->name, money($order->total), $order->payment_method === 'cod' ? 'Cash on delivery' : 'Paid online',
                ]);
            }
        }
    }

    public function shipped(Order $order): void
    {
        $this->toCustomer($order, 'shipped', setting('whatsapp_api.template_shipped'), [
            self::firstName($order), $order->number, $order->courier ?: 'Delhivery', $order->tracking_number ?: '-', $order->trackingUrl() ?: self::trackLink($order),
        ]);
    }

    public function delivered(Order $order): void
    {
        $this->toCustomer($order, 'delivered', setting('whatsapp_api.template_delivered'), [
            self::firstName($order), $order->number, url('/'),
        ]);
    }

    private function toCustomer(Order $order, string $event, ?string $template, array $params, ?array $document = null): void
    {
        if (! self::live() || ! $template || ! ($to = WhatsApp::number($order->phone))) {
            return;
        }
        // Each step is sent once, however many times it is triggered.
        if (NotificationLog::where('order_id', $order->id)->where('event', $event)->where('status', 'sent')->exists()) {
            return;
        }
        $this->wa->sendForOrder($order, $event, $to, $template, $params, $document);
    }

    private static function live(): bool
    {
        return WhatsApp::enabled();
    }

    private static function firstName(Order $order): string
    {
        return Str::before(trim($order->name), ' ') ?: $order->name;
    }

    private static function trackLink(Order $order): string
    {
        return route('track', ['number' => $order->number, 'phone' => $order->phone]);
    }

    /**
     * Texts for the admin's "Send on WhatsApp" buttons (wa.me links) — work without the API.
     *
     * @return array<string, string> label => message
     */
    public static function manualMessages(Order $order): array
    {
        $name = self::firstName($order);
        $items = $order->items->map(fn ($i) => '• '.$i->name.' × '.$i->qty)->implode("\n");
        $msgs = [
            'Order confirmation' => "Hi {$name}! 🎁 Thank you for your order *{$order->number}* at ".config('store.name').".\n\n{$items}\n\nTotal: *".money($order->total).'*'
                .($order->payment_method === 'cod' ? ' (cash on delivery)' : ' (paid)')
                ."\n\nInvoice: ".Invoice::publicUrl($order)."\nTrack your order: ".self::trackLink($order),
            'Photo check' => "Hi {$name}, this is ".config('store.name')." about order *{$order->number}*. We're preparing your personalised gift — could you please confirm the photos/text before we print? 😊",
        ];
        if ($order->tracking_number) {
            $msgs['Shipped'] = "Hi {$name}! 🚚 Your order *{$order->number}* is on its way with ".($order->courier ?: 'Delhivery').".\nTracking number: *{$order->tracking_number}*\nTrack: ".($order->trackingUrl() ?: self::trackLink($order))
                .($order->payment_method === 'cod' && $order->payment_status !== 'paid' ? "\n\nPlease keep *".money($order->total).'* ready to pay on delivery.' : '');
        }
        if ($order->payment_status !== 'paid' && $order->payment_method !== 'cod') {
            $msgs['Payment reminder'] = "Hi {$name}, your order *{$order->number}* (".money($order->total).') is waiting for payment. Reply here if you need help completing it.';
        }
        $msgs['Delivered — ask for a review'] = "Hi {$name}! 💝 We hope you love your gift from order *{$order->number}*. If you have a moment, a review would mean a lot to us: ".url('/');

        return $msgs;
    }
}

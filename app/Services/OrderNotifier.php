<?php

namespace App\Services;

use App\Http\Controllers\ReviewRequestController;
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
            self::firstName($order), $order->number, ReviewRequestController::link($order),
        ]);
    }

    private function toCustomer(Order $order, string $event, ?string $template, array $params, ?array $document = null): void
    {
        if (! self::live() || ! $template || ! ($to = WhatsApp::number($order->phone))) {
            return;
        }
        // Each step is sent once, however many times it is triggered.
        if (NotificationLog::where('order_id', $order->id)->where('channel', 'whatsapp')->where('event', $event)->where('status', 'sent')->exists()) {
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
     * Ready-written WhatsApp messages for the admin order page (wa.me links — no API needed).
     * Each: label, text, and whether it fits the order's stage right now (shown first).
     *
     * @return array<int, array{label: string, text: string, now: bool}>
     */
    public static function waMessages(Order $order): array
    {
        $order->loadMissing('items.product');
        $shop = config('store.name');
        $hi = 'Hi '.self::firstName($order).'! ';
        $no = '*'.$order->number.'*';
        $items = $order->items->map(fn ($i) => '• '.$i->name.' × '.$i->qty)->implode("\n");
        $total = '*'.money($order->total).'*';
        $paid = $order->payment_status === 'paid';
        $cod = $order->payment_method === 'cod';
        $s = $order->status;
        $open = in_array($s, ['pending', 'confirmed', 'in_production'], true);
        $days = max(1, (int) $order->items->max(fn ($i) => $i->product?->production_days ?? 2));
        $shipBy = now()->addWeekdays($days)->format('D, j M');
        $track = self::trackLink($order);

        // What a failed online order would come to as cash on delivery.
        $itemsAfterCoupon = (float) $order->subtotal - (float) $order->discount + (float) $order->prepaid_discount;
        $codTotal = $itemsAfterCoupon + (float) $order->shipping + Cart::codCharge($itemsAfterCoupon + (float) $order->shipping);

        $payLink = $order->payLinkProblem() ? null : \App\Http\Controllers\PayLinkController::link($order);

        $m = [];
        $add = function (string $label, string $text, bool $now) use (&$m) {
            $m[] = ['label' => $label, 'text' => $text, 'now' => $now];
        };

        // Payment
        $failed = ! $cod && ! $paid && $s === 'pending';
        $add('Payment failed — pay again or COD', $hi."This is {$shop} 🎁 We saw your order {$no} but the online payment didn’t go through, so it isn’t confirmed yet.\n\n{$items}\n\nWould you like to:\n1️⃣ Pay online again ({$total})".($payLink ? ': '.$payLink : ' — we’ll send you a payment link')."\n2️⃣ Pay *cash on delivery* instead — total *".money($codTotal).'* (includes COD charges)'."\n\nJust reply 1 or 2 and we’ll take care of the rest 😊", $failed);
        if ($payLink) {
            $add('Payment link', $hi.'You can pay *'.money($order->payableOnline())."* for your order {$no} securely here — UPI, GPay, PhonePe, cards or netbanking:\n{$payLink}\n\nYour order is confirmed as soon as the payment goes through ✅", ! $paid && ! $cod && $s === 'pending');
        }
        $add('Payment reminder', $hi."Your order {$no} ({$total}) is waiting for payment. Reply here if you need help completing it, or if you’d prefer cash on delivery.", $failed);
        if ($cod && ! $paid && in_array($s, ['confirmed', 'pending'], true)) {
            $add('Confirm COD order', $hi."Thank you for ordering from {$shop} 🎁\n\nOrder {$no}\n{$items}\n\nTotal to pay on delivery: {$total}\nDelivery to: {$order->address_line1}, {$order->city} {$order->pincode}\n\nPlease reply *YES* to confirm your cash on delivery order — we start making your personalised gift as soon as you confirm. ✅", ! $order->tracking_number);
        }
        $add('Confirm address', $hi."Before we send order {$no}, could you please confirm your delivery address?\n\n{$order->name}\n{$order->address_line1}".($order->address_line2 ? ', '.$order->address_line2 : '')."\n{$order->city}, {$order->state} {$order->pincode}\n📞 {$order->phone}\n\nReply *OK* if it’s correct, or send the right address 📍", $open && ! $order->tracking_number && $s !== 'pending');
        if ($paid) {
            $add('Payment received', $hi."We’ve received your payment of {$total} for order {$no} ✅ Thank you! We’ll start making your gift now.", $s === 'confirmed');
        }

        // Making
        $add('Order confirmation', $hi."🎁 Thank you for your order {$no} at {$shop}.\n\n{$items}\n\nTotal: {$total}".($cod ? ' (cash on delivery)' : ($paid ? ' (paid)' : ''))."\n\nInvoice: ".Invoice::publicUrl($order)."\nTrack your order: {$track}", $s === 'confirmed' && ($paid || ! $cod));
        $add('Photo check', $hi."This is {$shop} about order {$no}. We’re preparing your personalised gift — could you please confirm the photos/text are final before we print? 😊", in_array($s, ['confirmed', 'in_production'], true));
        $add('Need a better photo', $hi."About order {$no}: one of your photos is a little low in quality and may print blurry 😕 Could you send the original photo here on WhatsApp (as a *document* if possible, so it isn’t compressed)? We’ll use that instead.", false);
        $add('Design preview — please approve', $hi."Here’s the design preview for order {$no} 👇 (sending it next)\n\nPlease reply *OK* to print, or tell us what to change. We’ll start printing once you approve. ✨", $s === 'in_production');
        $add('Being made', $hi."Good news — your gift for order {$no} is being made right now 🛠️ We expect to ship it by *{$shipBy}*. We’ll send the tracking number as soon as it’s on its way.", $s === 'in_production');
        $add('Sorry for the delay', $hi."We’re sorry — order {$no} is taking a little longer than planned 🙏 We want to get every detail right. It should ship by *{$shipBy}*, and we’ll send tracking straight away. Thank you for your patience!", false);

        // Shipping
        if ($order->tracking_number) {
            $link = $order->trackingUrl() ?: $track;
            $add('Shipped', $hi."🚚 Your order {$no} is on its way with ".($order->courier ?: 'Delhivery').".\nTracking number: *{$order->tracking_number}*\nTrack: {$link}"
                .($cod && ! $paid ? "\n\nPlease keep {$total} ready to pay on delivery." : ''), $s === 'shipped');
            $add('Out for delivery', $hi."Your order {$no} is *out for delivery today* 📦".($cod && ! $paid ? " Please keep {$total} ready in cash/UPI for the delivery partner." : '').' Please keep your phone handy for the delivery call.', false);
            $add('Delivery attempt failed', $hi."The courier tried to deliver order {$no} but couldn’t reach you 😕 Please keep your phone on — they’ll try again. If you’d like a different time or address, reply here and we’ll arrange it.\nTrack: {$link}", false);
        }

        // After delivery
        $add('Delivered — ask for a review', $hi."💝 We hope you love your gift from order {$no}! If you have a moment, a quick review (a photo too, if you like) would mean a lot to us: ".ReviewRequestController::link($order)
            .(preg_match('#^https://#i', (string) setting('reviews.google_url')) ? "\n\nAnd if you have a moment more, a Google review helps others find us: ".setting('reviews.google_url') : ''), $s === 'delivered');
        $add('Thank you — order again', $hi."Thank you for choosing {$shop} 💝 Whenever you need another personalised gift — birthdays, anniversaries, festivals — we’re just a message away: ".url('/').'/', false);

        // Problems
        $add('Order cancelled', $hi."Your order {$no} has been cancelled as requested.".($paid ? " Your refund of {$total} has been started and usually reaches your account in 5–7 working days." : '').' We hope to make something special for you another time 💝', $s === 'cancelled');
        $add('Parcel returned to us', $hi."Your parcel for order {$no} came back to us as the courier couldn’t deliver it 📦 Would you like us to send it again? Please confirm your address and a good time to reach you.", $s === 'returned');

        // Stage-relevant first, keeping the order above.
        usort($m, fn ($a, $b) => $b['now'] <=> $a['now']);

        return $m;
    }
}

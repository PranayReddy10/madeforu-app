<?php

namespace App\Console\Commands;

use App\Mail\CartReminder;
use App\Models\AbandonedCart;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Services\CartRecovery;
use App\Services\WhatsApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendCartReminders extends Command
{
    protected $signature = 'store:cart-reminders';

    protected $description = 'Remind shoppers who left items in their cart (email, and WhatsApp when set up)';

    public function handle(WhatsApp $wa): int
    {
        if (! CartRecovery::enabled()) {
            return self::SUCCESS;
        }
        $first = max(0.25, (float) setting('abandoned.first_hours', 1));
        $second = max($first + 1, (float) setting('abandoned.second_hours', 24));
        $sent = 0;

        $due = AbandonedCart::whereNull('recovered_at')->whereNull('unsubscribed_at')->where('value', '>', 0)
            ->where('updated_at', '>=', now()->subDays(7))
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('reminders_sent', 0)->where('updated_at', '<=', now()->subMinutes((int) ($first * 60))))
                ->orWhere(fn ($q) => $q->where('reminders_sent', 1)->where('updated_at', '<=', now()->subMinutes((int) ($second * 60)))))
            ->get();

        foreach ($due as $cart) {
            // Ordered since (another device, or by phone)? Then it isn't abandoned.
            $order = Order::where('created_at', '>=', $cart->created_at)->whereNotIn('status', ['pending', 'cancelled'])
                ->where(fn ($q) => $q->when($cart->email, fn ($q) => $q->orWhere('email', $cart->email))->when($cart->phone, fn ($q) => $q->orWhere('phone', $cart->phone)))
                ->first();
            if ($order) {
                $cart->update(['recovered_order_id' => $order->id, 'recovered_at' => now()]);

                continue;
            }
            $items = $cart->items();
            if (! $items) {
                $cart->update(['reminders_sent' => 2]);   // everything in it is gone

                continue;
            }
            $n = $cart->reminders_sent + 1;
            $coupon = $n === 2 ? (setting('abandoned.coupon') ?: null) : null;

            if ($cart->email) {
                try {
                    Mail::to($cart->email)->send(new CartReminder($cart, $items, $n, $coupon));
                } catch (\Throwable $e) {
                    $this->warn("Mail to {$cart->email}: ".$e->getMessage());
                }
            }
            if ($cart->phone && WhatsApp::enabled() && ($template = setting('abandoned.whatsapp_template')) && ($to = WhatsApp::number($cart->phone))) {
                $first = $items[0]['product']->name;
                try {
                    $id = $wa->template($to, $template, [
                        Str::before(trim((string) $cart->name), ' ') ?: 'there',
                        Str::limit($first, 60).(count($items) > 1 ? ' + '.(count($items) - 1).' more' : ''),
                        $cart->recoverUrl().($coupon ? '?coupon='.$coupon : ''),
                    ]);
                    NotificationLog::create(['channel' => 'whatsapp', 'event' => 'cart_reminder_'.$n, 'to' => $to, 'status' => 'sent', 'provider_id' => $id]);
                } catch (\Throwable $e) {
                    NotificationLog::create(['channel' => 'whatsapp', 'event' => 'cart_reminder_'.$n, 'to' => $to, 'status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
                }
            }
            // Don't touch updated_at: the second reminder is timed from when the cart was left.
            $cart->timestamps = false;
            $cart->forceFill(['reminders_sent' => $n, 'last_reminded_at' => now()])->save();
            $sent++;
        }
        $this->info("Sent {$sent} cart reminders.");

        return self::SUCCESS;
    }
}

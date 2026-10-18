<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cashfree Payment Gateway (PG API 2023-08-01), without the SDK: create an order, read it back to
 * confirm payment, and check webhook signatures.
 */
class Cashfree
{
    private const VERSION = '2023-08-01';

    public static function enabled(): bool
    {
        return (bool) setting('cashfree.enabled') && setting('cashfree.app_id') && setting('cashfree.secret');
    }

    public static function mode(): string
    {
        return setting('cashfree.mode') === 'sandbox' ? 'sandbox' : 'production';
    }

    private function base(): string
    {
        return self::mode() === 'sandbox' ? 'https://sandbox.cashfree.com/pg' : 'https://api.cashfree.com/pg';
    }

    private function http()
    {
        return Http::withHeaders([
            'x-client-id' => (string) setting('cashfree.app_id'),
            'x-client-secret' => (string) setting('cashfree.secret'),
            'x-api-version' => self::VERSION,
        ])->acceptJson()->timeout(20);
    }

    /** Cashfree refuses a reused order_id, so each payment attempt gets its own: MFU261018001-k3x9. */
    public static function newOrderId(Order $order): string
    {
        return $order->number.'-'.Str::lower(Str::random(4));
    }

    /** @return array{payment_session_id: string, order_id: string} */
    public function createOrder(Order $order, string $cfOrderId): array
    {
        $res = $this->http()->post($this->base().'/orders', [
            'order_id' => $cfOrderId,
            'order_amount' => round((float) $order->total, 2),
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => 'c'.$order->phone,
                'customer_name' => Str::limit($order->name, 100, ''),
                'customer_email' => $order->email,
                'customer_phone' => $order->phone,
            ],
            'order_meta' => [
                'return_url' => route('checkout.cashfree.return').'?order_id={order_id}',
                'notify_url' => route('webhooks.cashfree'),
            ],
            'order_note' => 'Order '.$order->number,
        ]);
        if (! $res->successful() || ! $res->json('payment_session_id')) {
            throw new RuntimeException('Cashfree order failed: '.$res->body());
        }

        return $res->json();
    }

    /**
     * Ask Cashfree whether the order is paid. Never trust the browser's return trip alone.
     *
     * @return array{paid: bool, status: ?string, payment_id: ?string, amount: ?float}
     */
    public function status(string $cfOrderId): array
    {
        $order = $this->http()->get($this->base().'/orders/'.urlencode($cfOrderId));
        if (! $order->successful()) {
            throw new RuntimeException('Cashfree order lookup failed: '.$order->body());
        }
        $paymentId = null;
        if ($order->json('order_status') === 'PAID') {
            $payments = $this->http()->get($this->base().'/orders/'.urlencode($cfOrderId).'/payments');
            $ok = collect($payments->json() ?: [])->firstWhere('payment_status', 'SUCCESS');
            $paymentId = $ok ? (string) $ok['cf_payment_id'] : null;
        }

        return [
            'paid' => $order->json('order_status') === 'PAID',
            'status' => $order->json('order_status'),
            'payment_id' => $paymentId,
            'amount' => $order->json('order_amount') !== null ? (float) $order->json('order_amount') : null,
        ];
    }

    /** Webhook signature: base64(HMAC-SHA256(timestamp + raw body, secret key)). */
    public function verifyWebhook(string $body, string $timestamp, string $signature): bool
    {
        $secret = (string) setting('cashfree.secret');
        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $timestamp.$body, $secret, true)), $signature);
    }
}

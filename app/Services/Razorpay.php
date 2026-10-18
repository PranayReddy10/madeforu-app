<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The three Razorpay calls the store needs, without the SDK: create an order, verify a checkout, verify a webhook. */
class Razorpay
{
    public static function enabled(): bool
    {
        return (bool) setting('razorpay.enabled') && setting('razorpay.key') && setting('razorpay.secret');
    }

    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        $res = Http::withBasicAuth(setting('razorpay.key'), setting('razorpay.secret'))
            ->timeout(20)->acceptJson()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amountPaise,
                'currency' => 'INR',
                'receipt' => $receipt,
                'notes' => $notes,
            ]);
        if (! $res->successful()) {
            throw new RuntimeException('Razorpay order failed: '.$res->body());
        }

        return $res->json();
    }

    public function verifyPayment(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, (string) setting('razorpay.secret'));

        return hash_equals($expected, $signature);
    }

    public function verifyWebhook(string $body, string $signature): bool
    {
        $secret = (string) setting('razorpay.webhook_secret');

        return $secret !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }
}

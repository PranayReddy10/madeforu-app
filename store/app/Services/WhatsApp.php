<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WhatsApp Business Cloud API (Meta). Business-started messages must use templates approved in
 * WhatsApp Manager; the placeholders {{1}}, {{2}}… are filled in the order documented in the README.
 */
class WhatsApp
{
    public static function enabled(): bool
    {
        return (bool) setting('whatsapp_api.enabled') && setting('whatsapp_api.phone_number_id') && setting('whatsapp_api.token');
    }

    /** 98765 43210 / +91-98765-43210 → 919876543210 */
    public static function number(string $phone): ?string
    {
        $d = preg_replace('/\D/', '', $phone);
        if (strlen($d) === 10) {
            $d = '91'.$d;
        }

        return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
    }

    /**
     * Send one template message. Returns the WhatsApp message id; throws on failure.
     *
     * @param  array<int, string>  $params  body placeholders in order
     * @param  array{link: string, filename: string}|null  $document  header document (e.g. the invoice PDF)
     */
    public function template(string $to, string $template, array $params, ?array $document = null): string
    {
        $components = [];
        if ($document) {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => $document]]];
        }
        if ($params) {
            $components[] = ['type' => 'body', 'parameters' => array_map(
                // WhatsApp refuses empty values, new lines and long runs of spaces inside a parameter.
                fn ($p) => ['type' => 'text', 'text' => Str::limit(trim(preg_replace('/\s+/', ' ', (string) $p)) ?: '-', 900, '')],
                array_values($params),
            )];
        }
        $res = Http::withToken((string) setting('whatsapp_api.token'))->acceptJson()->timeout(20)
            ->post('https://graph.facebook.com/v21.0/'.setting('whatsapp_api.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => ['name' => $template, 'language' => ['code' => (string) setting('whatsapp_api.language', 'en')], 'components' => $components],
            ]);
        $id = $res->json('messages.0.id');
        if (! $res->successful() || ! $id) {
            throw new \RuntimeException($res->json('error.error_data.details') ?: $res->json('error.message') ?: 'HTTP '.$res->status());
        }

        return $id;
    }

    /** Send and record the result against the order. Never throws: a WhatsApp hiccup must not break checkout. */
    public function sendForOrder(Order $order, string $event, string $to, string $template, array $params, ?array $document = null): bool
    {
        try {
            $id = $this->template($to, $template, $params, $document);
            NotificationLog::create(['order_id' => $order->id, 'channel' => 'whatsapp', 'event' => $event, 'to' => $to, 'status' => 'sent', 'provider_id' => $id]);

            return true;
        } catch (\Throwable $e) {
            NotificationLog::create(['order_id' => $order->id, 'channel' => 'whatsapp', 'event' => $event, 'to' => $to, 'status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);

            return false;
        }
    }
}

<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends delivered orders to the sale panel (sale.madeforu.co.in, the `sale` app in this repository),
 * where the shop keeps its accounts. The panel's api/website.php adds or updates the order, matched on
 * our order number, under its "Website orders" event. Set up in Admin → Settings → Sale panel sync with
 * the address and key from the panel's Website sync page.
 */
class SalePanel
{
    /** sale_order_no for an order the shop typed into the panel by hand: never sent, never removed. */
    public const MANUAL = 'manual';

    public static function configured(): bool
    {
        return filled(setting('sale.url')) && filled(setting('sale.key'));
    }

    public static function enabled(): bool
    {
        return (bool) setting('sale.enabled') && self::configured();
    }

    /** @return array{ok: bool, message: string, data?: array} */
    private static function call(string $action, array $body): array
    {
        $key = (string) setting('sale.key');
        $url = rtrim((string) setting('sale.url'), '/').'/api/website.php';
        try {
            // The key goes in the body as well: some hosts drop custom headers before PHP sees them.
            $res = Http::asJson()->acceptJson()->timeout(20)->withHeaders(['X-Website-Key' => $key])
                ->post($url, ['action' => $action, 'key' => $key] + $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach the sale panel at '.$url.': '.Str::limit($e->getMessage(), 150)];
        }
        $json = $res->json();
        if (! is_array($json)) {
            return ['ok' => false, 'message' => 'The sale panel answered HTTP '.$res->status().' without JSON — check the address ('.$url.') and that api/website.php is uploaded.'];
        }
        if (empty($json['ok'])) {
            return ['ok' => false, 'message' => (string) ($json['error']['message'] ?? 'The sale panel refused it (HTTP '.$res->status().').')];
        }

        return ['ok' => true, 'message' => (string) ($json['message'] ?? 'OK'), 'data' => $json];
    }

    public static function ping(): array
    {
        return self::call('ping', []);
    }

    /** What the panel stores: our items at the prices sold, charges and the payment as received. */
    public static function payload(Order $order): array
    {
        $order->loadMissing('items.product', 'items.variant');
        $charges = array_filter([
            'Shipping' => (float) $order->shipping,
            'COD' => (float) $order->cod_fee,
            ($order->extra_charge_label ?: 'Extra') => (float) $order->extra_charge,
        ], fn ($v) => $v > 0);
        $coupon = (float) $order->discount - (float) $order->prepaid_discount;
        $cod = $order->payment_method === 'cod';
        $paid = $order->payment_status === 'paid';
        $soldOn = $order->delivered_at ?? $order->shipped_at ?? $order->created_at;

        return [
            'web_order_no' => $order->number,
            'name' => $order->name,
            'phone' => $order->phone,
            'date' => $soldOn->format('Y-m-d H:i:s'),
            'items' => $order->items->map(fn ($i) => [
                'name' => Str::limit($i->name.($i->options ? ' ('.implode(' / ', $i->options).')' : ''), 60, ''),
                'qty' => (int) $i->qty,
                'unit_price' => (float) $i->unit_price,
                'unit_cost' => (float) ($i->unit_cost ?? $i->product?->costFor($i->variant) ?? 0),
            ])->values()->all(),
            'discount' => round((float) $order->discount, 2),
            'discount_reason' => collect([
                $coupon > 0 ? 'Coupon '.($order->coupon_code ?: '') : null,
                (float) $order->prepaid_discount > 0 ? 'Online payment discount' : null,
            ])->filter()->implode(' + ') ?: null,
            'extra_charge' => round(array_sum($charges), 2),
            'extra_charge_reason' => collect($charges)->map(fn ($v, $k) => $k.' ₹'.rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'))->implode(' + ') ?: null,
            'payment' => $paid ? [
                'amount' => round((float) $order->total, 2),
                'mode' => $cod ? 'cash' : 'other',
                'note' => $cod ? 'COD via '.($order->courier ?: 'Delhivery').($order->tracking_number ? ', AWB '.$order->tracking_number : '')
                    : ucfirst((string) $order->payment_method).' '.($order->razorpay_payment_id ?: $order->cashfree_payment_id ?: ''),
            ] : ['amount' => 0],
            'awb' => $order->tracking_number,
            'dispatch_date' => $order->shipped_at?->format('Y-m-d'),
            'notes' => 'Website order '.$order->number.' · '.($cod ? 'cash on delivery' : 'paid online'),
        ];
    }

    /** Add or update the order in the panel; the result is kept on the order. */
    public static function push(Order $order): bool
    {
        $r = self::call('upsert', self::payload($order));
        $order->forceFill($r['ok']
            ? ['sale_order_no' => $r['data']['order_no'] ?? $order->sale_order_no, 'sale_synced_at' => now(), 'sale_sync_error' => null]
            : ['sale_sync_error' => Str::limit($r['message'], 250)])->saveQuietly();

        return $r['ok'];
    }

    /** The order was cancelled or returned after it had been sent. */
    public static function remove(Order $order): bool
    {
        $r = self::call('remove', ['web_order_no' => $order->number]);
        $order->forceFill($r['ok'] ? ['sale_order_no' => null, 'sale_synced_at' => null, 'sale_sync_error' => null]
            : ['sale_sync_error' => Str::limit($r['message'], 250)])->saveQuietly();

        return $r['ok'];
    }
}

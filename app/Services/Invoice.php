<?php

namespace App\Services;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Tax invoices. Site prices include GST, so tax is worked backwards from each amount:
 * taxable = amount / (1 + rate). Same state as the business: CGST + SGST; other states: IGST.
 */
class Invoice
{
    /** Give a confirmed order its invoice number once: PREFIX/26-27/0001, counting from 1 every April. */
    public static function assign(Order $order): string
    {
        if ($order->invoice_number) {
            return $order->invoice_number;
        }

        return DB::transaction(function () use ($order) {
            $o = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($o->invoice_number) {
                return $o->invoice_number;
            }
            $now = now();
            $start = $now->month >= 4 ? $now->year : $now->year - 1;
            $prefix = trim((string) setting('invoice.prefix', 'MFU'), '/').'/'.substr((string) $start, 2).'-'.substr((string) ($start + 1), 2).'/';
            $last = Order::where('invoice_number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('invoice_number')->value('invoice_number');
            $number = $prefix.str_pad((string) ($last ? ((int) substr($last, strlen($prefix))) + 1 : 1), 4, '0', STR_PAD_LEFT);
            $o->forceFill(['invoice_number' => $number, 'invoiced_at' => $now])->save();
            $order->forceFill(['invoice_number' => $number, 'invoiced_at' => $now]);

            return $number;
        });
    }

    /**
     * Lines and tax breakup for the PDF. The order discount is shared across items by value.
     *
     * @return array{gst: bool, intra: bool, lines: array, charges: array, taxes: array, taxable: float, tax: float, total: float}
     */
    public static function breakdown(Order $order): array
    {
        $order->loadMissing('items.product');
        $gst = (bool) setting('invoice.gstin');
        $intra = strcasecmp(trim((string) $order->state), trim((string) setting('invoice.state'))) === 0;
        $default = (float) setting('invoice.gst_rate', 18);
        $itemsTotal = (float) $order->items->sum('line_total');
        $discount = (float) $order->discount;

        $split = function (float $gross, float $rate) use ($gst) {
            if (! $gst || $rate <= 0) {
                return [round($gross, 2), 0.0];
            }
            $taxable = round($gross / (1 + $rate / 100), 2);

            return [$taxable, round($gross - $taxable, 2)];
        };

        $lines = [];
        $taxes = [];
        foreach ($order->items as $item) {
            $share = $itemsTotal > 0 ? $discount * (float) $item->line_total / $itemsTotal : 0;
            $gross = (float) $item->line_total - $share;
            $rate = $item->product?->gst_rate !== null ? (float) $item->product->gst_rate : $default;
            [$taxable, $tax] = $split($gross, $rate);
            $lines[] = [
                'name' => $item->name.($item->options ? ' ('.implode(' / ', $item->options).')' : ''),
                'hsn' => $item->product?->hsn_code ?: setting('invoice.hsn'),
                'qty' => $item->qty, 'unit' => (float) $item->unit_price, 'discount' => round($share, 2),
                'rate' => $rate, 'taxable' => $taxable, 'tax' => $tax, 'total' => round($gross, 2),
            ];
            $taxes[(string) $rate] = ($taxes[(string) $rate] ?? 0) + $tax;
        }
        $charges = [];
        foreach (['Shipping' => (float) $order->shipping, 'Cash on delivery charges' => (float) $order->cod_fee] as $label => $amount) {
            if ($amount > 0) {
                [$taxable, $tax] = $split($amount, $default);
                $charges[] = ['name' => $label, 'rate' => $default, 'taxable' => $taxable, 'tax' => $tax, 'total' => $amount];
                $taxes[(string) $default] = ($taxes[(string) $default] ?? 0) + $tax;
            }
        }
        $all = array_merge($lines, $charges);

        return [
            'gst' => $gst,
            'intra' => $intra,
            'lines' => $lines,
            'charges' => $charges,
            'taxes' => array_filter(array_map(fn ($t) => round($t, 2), $taxes)),
            'taxable' => round(array_sum(array_column($all, 'taxable')), 2),
            'tax' => round(array_sum(array_column($all, 'tax')), 2),
            'total' => (float) $order->total,
        ];
    }

    public static function pdf(Order $order): \Barryvdh\DomPDF\PDF
    {
        self::assign($order);

        return Pdf::loadView('invoices.invoice', [
            'order' => $order,
            'b' => self::breakdown($order),
            'logo' => 'data:image/png;base64,'.base64_encode((string) @file_get_contents(public_path('images/logo.png'))),
        ])->setPaper('a4');
    }

    public static function filename(Order $order): string
    {
        return 'Invoice-'.str_replace('/', '-', (string) ($order->invoice_number ?: $order->number)).'.pdf';
    }

    /** A link the customer can open without logging in (emails, WhatsApp). */
    public static function publicUrl(Order $order, int $days = 30): string
    {
        return URL::temporarySignedRoute('invoice.download', now()->addDays($days), ['number' => $order->number]);
    }

    /** Indian words for the amount, as invoices usually show. */
    public static function inWords(float $amount): string
    {
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        $words = self::words($rupees) ?: 'Zero';

        return 'Rupees '.$words.($paise ? ' and '.self::words($paise).' Paise' : '').' Only';
    }

    private static function words(int $n): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
            'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = fn ($x) => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)].' '.$ones[$x % 10]);
        $out = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $name]) {
            if ($n >= $size) {
                $out[] = ($size === 100 ? $ones[intdiv($n, $size)] : self::words(intdiv($n, $size))).' '.$name;
                $n %= $size;
            }
        }
        if ($n) {
            $out[] = $two($n);
        }

        return implode(' ', $out);
    }
}

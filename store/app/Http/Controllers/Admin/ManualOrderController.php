<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Controller;
use App\Models\CustomizationField;
use App\Models\Order;
use App\Models\Product;
use App\Services\Cart;
use App\Services\OrderConfirmation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Orders → New order: orders taken on WhatsApp, Instagram or the phone, typed in like a
 * POS. The customer's photos are already in the chat, so nothing is uploaded. Once saved, the order
 * is a normal confirmed order: invoice, stock, customer messages, Delhivery, Sales, sale panel.
 */
class ManualOrderController extends Controller
{
    public const SOURCES = ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'phone' => 'Phone call', 'walk_in' => 'In person', 'other' => 'Other'];

    /** How the customer paid: online-style (prepaid) or cash on delivery. */
    public const PAYMENTS = ['upi' => 'Paid — UPI / GPay / PhonePe', 'bank' => 'Paid — bank transfer', 'cod' => 'Cash on delivery'];

    public function create()
    {
        $products = Product::active()->with(['variants', 'customizationFields'])->orderBy('name')->get();

        return view('admin.orders.create', [
            'catalog' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => $p->currentPrice(),
                'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'label' => implode(' / ', (array) $v->options), 'price' => $v->currentPrice()])->values(),
                'fields' => $p->customizationFields->where('type', '!=', 'images')->map(fn (CustomizationField $f) => [
                    'id' => $f->id, 'label' => $f->label, 'type' => $f->type, 'required' => (bool) $f->required, 'extra' => (float) $f->extra_price,
                    'options' => in_array($f->type, ['select', 'choice'], true)
                        ? collect($f->options)->map(fn ($o) => ['label' => $o['label'] ?? '', 'price' => (float) ($o['price'] ?? 0)])->values() : [],
                ])->values(),
                'photos' => $p->customizationFields->contains('type', 'images'),
                'photoExtra' => (float) $p->customizationFields->where('type', 'images')->sum('extra_price'),
            ])->values(),
            'states' => CheckoutController::STATES,
            'freeOver' => (float) setting('free_shipping_over'),
            'shippingFee' => (float) setting('shipping_fee'),
            'codFixed' => (float) setting('cod_fee'),
            'codPercent' => (float) setting('cod_fee_percent'),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['phone' => substr(preg_replace('/\D/', '', (string) $request->input('phone')), -10)]);
        $data = $request->validate([
            'source' => 'required|in:'.implode(',', array_keys(self::SOURCES)),
            'name' => 'required|string|max:120',
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'email' => 'nullable|email|max:160',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:80',
            'state' => 'required|in:'.implode(',', CheckoutController::STATES),
            'pincode' => ['required', 'regex:/^[1-9]\d{5}$/'],
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.qty' => 'required|integer|min:1|max:999',
            'items.*.unit_price' => 'nullable|numeric|min:0|max:1000000',
            'items.*.custom' => 'nullable|array',
            'payment' => 'required|in:'.implode(',', array_keys(self::PAYMENTS)),
            'payment_ref' => 'nullable|string|max:100',
            'shipping' => 'nullable|numeric|min:0|max:100000',
            'cod_fee' => 'nullable|numeric|min:0|max:100000',
            'discount' => 'nullable|numeric|min:0|max:1000000',
            'extra_charge' => 'nullable|numeric|min:0|max:100000',
            'extra_charge_label' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:1000',
            'notify' => 'nullable|boolean',
        ], [
            'phone.regex' => 'Enter the customer’s 10-digit mobile number.',
            'pincode.regex' => 'Enter a valid 6-digit PIN code.',
            'items.required' => 'Add at least one product.',
        ]);

        $products = Product::with(['variants', 'customizationFields'])->whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($data['items'] as $k => $row) {
            $p = $products->get($row['product_id']);
            if (! $p) {
                throw ValidationException::withMessages(["items.$k.product_id" => 'Item '.($k + 1).': that product no longer exists.']);
            }
            $variant = ! empty($row['variant_id']) ? $p->variants->firstWhere('id', (int) $row['variant_id']) : null;
            if ($p->type === 'variable' && $p->variants->isNotEmpty() && ! $variant) {
                throw ValidationException::withMessages(["items.$k.variant_id" => 'Item '.($k + 1).': choose the '.$p->name.' option (size, colour…).']);
            }
            $unit = $variant ? $variant->currentPrice() : $p->currentPrice();
            $custom = [];
            foreach ($p->customizationFields as $f) {
                if ($f->type === 'images') {
                    // The photos are in the chat; the production sheet says where to find them.
                    $unit += (float) $f->extra_price;
                    $custom[] = ['label' => $f->label, 'type' => 'text', 'value' => 'Photos on '.self::SOURCES[$data['source']].' chat', 'files' => [], 'slots' => [], 'image' => null];

                    continue;
                }
                $value = trim((string) ($row['custom'][$f->id] ?? ''));
                if ($value === '') {
                    continue;
                }
                $opt = in_array($f->type, ['select', 'choice'], true) ? collect($f->options)->firstWhere('label', $value) : null;
                if (in_array($f->type, ['select', 'choice'], true) && ! $opt) {
                    throw ValidationException::withMessages(["items.$k.custom" => 'Item '.($k + 1).': “'.$value.'” is not an option for '.$f->label.'.']);
                }
                $unit += (float) $f->extra_price + (float) ($opt['price'] ?? 0);
                $custom[] = ['label' => $f->label, 'type' => $f->type, 'value' => mb_substr($value, 0, 500), 'files' => [], 'slots' => [], 'image' => ! empty($opt['image']) ? media_url($opt['image']) : null];
            }
            // A price agreed in the chat wins over the list price.
            if (isset($row['unit_price']) && $row['unit_price'] !== '' && $row['unit_price'] !== null) {
                $unit = (float) $row['unit_price'];
            }
            $qty = (int) $row['qty'];
            $lines[] = compact('p', 'variant', 'custom', 'qty') + ['unit' => round($unit, 2), 'total' => round($unit * $qty, 2)];
        }

        $subtotal = round(array_sum(array_column($lines, 'total')), 2);
        $discount = min(round((float) ($data['discount'] ?? 0), 2), $subtotal);
        $cod = $data['payment'] === 'cod';
        $after = $subtotal - $discount;
        $shipping = $request->filled('shipping') ? round((float) $data['shipping'], 2)
            : ($after >= (float) setting('free_shipping_over') && (float) setting('free_shipping_over') > 0 ? 0.0 : (float) setting('shipping_fee'));
        $codFee = $cod ? ($request->filled('cod_fee') ? round((float) $data['cod_fee'], 2) : Cart::codCharge($after + $shipping)) : 0.0;
        $extra = round((float) ($data['extra_charge'] ?? 0), 2);
        $total = round($after + $shipping + $codFee + $extra, 2);

        $notes = collect([
            'Order taken on '.self::SOURCES[$data['source']].'.',
            ! $cod ? self::PAYMENTS[$data['payment']].($data['payment_ref'] ? ', ref '.$data['payment_ref'] : '').'.' : null,
            $data['notes'] ?? null,
        ])->filter()->implode(' ');

        $order = DB::transaction(function () use ($data, $lines, $subtotal, $discount, $shipping, $codFee, $extra, $total, $cod, $notes) {
            $order = Order::create([
                'number' => Order::nextNumber(),
                'status' => 'confirmed',
                'payment_method' => $data['payment'],
                'payment_status' => $cod ? 'unpaid' : 'paid',
                'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? '',
                'address_line1' => $data['address_line1'], 'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'], 'state' => $data['state'], 'pincode' => $data['pincode'],
                'subtotal' => $subtotal, 'discount' => $discount, 'prepaid_discount' => 0,
                'shipping' => $shipping, 'cod_fee' => $codFee,
                'extra_charge' => $extra, 'extra_charge_label' => $extra > 0 ? (trim((string) ($data['extra_charge_label'] ?? '')) ?: 'Extra charges') : null,
                'total' => $total,
                'notes' => $notes,
                'utm' => ['utm_source' => $data['source'], 'utm_medium' => 'manual order', 'entered_by' => auth()->user()?->name],
            ]);
            foreach ($lines as $l) {
                $order->items()->create([
                    'product_id' => $l['p']->id,
                    'variant_id' => $l['variant']?->id,
                    'name' => $l['p']->name,
                    'sku' => $l['variant']?->sku ?: $l['p']->sku,
                    'unit_cost' => $l['p']->costFor($l['variant']),
                    'options' => $l['variant']?->options,
                    'customization' => $l['custom'],
                    'qty' => $l['qty'],
                    'unit_price' => $l['unit'],
                    'line_total' => $l['total'],
                ]);
            }

            return $order;
        });

        // From here on it is an ordinary confirmed order.
        OrderConfirmation::run($order->fresh('items'), $request->boolean('notify'));

        return redirect()->route('admin.orders.show', $order)->with('status', 'Order '.$order->number.' created — '.money($order->total)
            .($cod ? ' cash on delivery.' : ' paid.').($request->boolean('notify') ? ' The customer has been sent the confirmation.' : ''));
    }
}

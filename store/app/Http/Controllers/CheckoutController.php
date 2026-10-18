<?php

namespace App\Http\Controllers;

use App\Jobs\CreateShipment;
use App\Jobs\NotifyOrder;
use App\Mail\OrderPlaced;
use App\Models\Coupon;
use App\Models\CustomerUpload;
use App\Models\Order;
use App\Models\Product;
use App\Services\Analytics;
use App\Services\Cart;
use App\Services\CartRecovery;
use App\Services\Cashfree;
use App\Services\Delhivery;
use App\Services\Invoice;
use App\Services\Razorpay;
use App\Services\Seo;
use App\Services\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckoutController extends Controller
{
    public const STATES = ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh',
        'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand',
        'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha',
        'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];

    public function __construct(private Cart $cart) {}

    public function index(Seo $seo, Analytics $analytics)
    {
        $lines = $this->cart->lines();
        if ($lines->isEmpty()) {
            return redirect()->route('cart');
        }
        $seo->title('Checkout')->noindex();
        $totals = $this->cart->totals($lines);
        $analytics->push('begin_checkout', [
            'currency' => 'INR', 'value' => $totals['total'], 'coupon' => $totals['coupon'],
            'items' => $lines->map(fn ($l) => $analytics->item($l->product, $l->unit, $l->qty, $l->variant?->label()))->all(),
        ]);
        $user = auth()->user();
        $last = $user?->orders()->first();
        $saved = session('checkout.prefill', []);

        return view('checkout', [
            'lines' => $lines,
            'totals' => $totals,
            'codTotals' => $this->cart->totals($lines, 'cod'),
            'states' => self::STATES,
            'prefill' => [
                'name' => $last->name ?? $user?->name ?? $saved['name'] ?? null, 'email' => $last->email ?? $user?->email ?? $saved['email'] ?? null,
                'phone' => $last->phone ?? $user?->phone ?? $saved['phone'] ?? null,
                'address_line1' => $last?->address_line1, 'address_line2' => $last?->address_line2, 'city' => $last?->city,
                'state' => $last?->state, 'pincode' => $last?->pincode,
            ],
            'gateways' => self::gateways(),
            'codAllowed' => $this->codAllowed($totals['total']),
        ]);
    }

    /** Online gateways that are switched on and configured, default first. */
    public static function gateways(): array
    {
        $on = array_keys(array_filter(['razorpay' => Razorpay::enabled(), 'cashfree' => Cashfree::enabled()]));
        $default = setting('payments.default');
        usort($on, fn ($a, $b) => ($b === $default) <=> ($a === $default));

        return $on;
    }

    public function place(Request $request, Razorpay $razorpay, Cashfree $cashfree)
    {
        // People type "+91 98765-43210", "098765 43210"…: keep only the 10-digit mobile number.
        $digits = preg_replace('/\D/', '', (string) $request->input('phone'));
        $request->merge(['phone' => strlen($digits) > 10 ? substr($digits, -10) : $digits, 'pincode' => preg_replace('/\s/', '', (string) $request->input('pincode'))]);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160',
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:80',
            'state' => 'required|in:'.implode(',', self::STATES),
            'pincode' => ['required', 'regex:/^[1-9]\d{5}$/'],
            'notes' => 'nullable|string|max:1000',
            'payment_method' => 'required|in:razorpay,cashfree,cod',
        ], ['phone.regex' => 'Please enter a 10-digit Indian mobile number.', 'pincode.regex' => 'Please enter a valid 6-digit PIN code.']);

        $lines = $this->cart->lines();
        if ($lines->isEmpty()) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }
        // Someone else may have bought the last one since this was added to the cart.
        if ($short = $this->cart->stockProblems($lines)) {
            return redirect()->route('cart')->with('error', implode(' ', $short));
        }
        $method = $data['payment_method'];
        if ($method !== 'cod' && ! in_array($method, self::gateways(), true)) {
            return back()->withInput()->withErrors(['payment_method' => 'That payment option is not available right now. Please choose another.']);
        }
        $totals = $this->cart->totals($lines, $method);
        if ($method === 'cod' && ! $this->codAllowed($totals['total'])) {
            return back()->withInput()->withErrors(['payment_method' => 'Cash on delivery is not available for this order. Please pay online.']);
        }
        // Delhivery must reach the PIN (and collect cash there for COD). If the check itself fails, let the order through.
        if (Delhivery::enabled() && setting('delhivery.check_pincodes')) {
            $pin = app(Delhivery::class)->pincode($data['pincode']);
            if ($pin && ! $pin['serviceable']) {
                return back()->withInput()->withErrors(['pincode' => 'Sorry, our courier does not deliver to '.$data['pincode'].' yet. Please use another address or contact us on WhatsApp.']);
            }
            if ($pin && $method === 'cod' && ! $pin['cod']) {
                return back()->withInput()->withErrors(['payment_method' => 'Cash on delivery is not available at '.$data['pincode'].'. Please pay online.']);
            }
        }

        $order = DB::transaction(function () use ($data, $lines, $totals, $method) {
            $order = Order::create([
                'number' => Order::nextNumber(),
                'user_id' => auth()->id(),
                'status' => $method === 'cod' ? 'confirmed' : 'pending',
                'payment_method' => $method,
                'payment_status' => 'unpaid',
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'coupon_code' => $totals['coupon'],
                'shipping' => $totals['shipping'],
                'cod_fee' => $totals['cod_fee'],
                'total' => $totals['total'],
                'utm' => session('utm'),
            ] + collect($data)->except('payment_method')->all());

            foreach ($lines as $l) {
                $item = $order->items()->create([
                    'product_id' => $l->product->id,
                    'variant_id' => $l->variant?->id,
                    'name' => $l->product->name,
                    'sku' => $l->variant?->sku ?: $l->product->sku,
                    'options' => $l->variant?->options,
                    'customization' => collect($l->details)->map(fn ($d) => [
                        'label' => $d->label, 'type' => $d->type, 'value' => $d->value,
                        'files' => $d->uploads->pluck('token')->all(),
                    ])->all(),
                    'qty' => $l->qty,
                    'unit_price' => $l->unit,
                    'line_total' => $l->total,
                ]);
                $tokens = collect($l->details)->flatMap(fn ($d) => $d->uploads->pluck('token'))->all();
                if ($tokens) {
                    CustomerUpload::whereIn('token', $tokens)->update(['order_item_id' => $item->id]);
                }
            }

            return $order;
        });

        if ($method === 'cod') {
            $this->complete($order);
            $this->forgetCart($order);

            return redirect()->route('checkout.thanks', $order->number);
        }

        session(['pending_order' => $order->number]);
        try {
            if ($method === 'cashfree') {
                $cfId = Cashfree::newOrderId($order);
                $cf = $cashfree->createOrder($order, $cfId);
                $order->update(['cashfree_order_id' => $cfId]);

                return view('pay-cashfree', ['order' => $order, 'sessionId' => $cf['payment_session_id'], 'mode' => Cashfree::mode()]);
            }
            $rp = $razorpay->createOrder((int) round($order->total * 100), $order->number, ['order' => $order->number]);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return redirect()->route('checkout')->with('error', 'We could not start the payment. Please try again, or choose another payment option.');
        }
        $order->update(['razorpay_order_id' => $rp['id']]);

        return view('pay', ['order' => $order, 'rp' => $rp]);
    }

    public function verify(Request $request, Razorpay $razorpay)
    {
        $data = $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);
        $order = Order::where('razorpay_order_id', $data['razorpay_order_id'])->firstOrFail();
        if (! $razorpay->verifyPayment($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            $order->update(['payment_status' => 'failed']);

            return redirect()->route('checkout')->with('error', 'Payment could not be verified. If money was deducted it will be refunded automatically, or contact us.');
        }
        $this->markPaid($order, 'razorpay', $data['razorpay_payment_id']);
        $this->forgetCart($order);

        return redirect()->route('checkout.thanks', $order->number);
    }

    /** Razorpay calls this even when the customer closed the tab before returning, so no paid order is lost. */
    public function webhook(Request $request, Razorpay $razorpay)
    {
        if (! $razorpay->verifyWebhook($request->getContent(), (string) $request->header('X-Razorpay-Signature'))) {
            return response('bad signature', 400);
        }
        $event = $request->input('event');
        $payment = $request->input('payload.payment.entity');
        if (in_array($event, ['payment.captured', 'order.paid'], true) && $payment) {
            $order = Order::where('razorpay_order_id', $payment['order_id'] ?? '')->first();
            if ($order && (int) ($payment['amount'] ?? 0) === (int) round($order->total * 100)) {
                $this->markPaid($order, 'razorpay', $payment['id']);
            }
        }

        return response('ok');
    }

    /** Cashfree sends the customer back here; ask Cashfree itself whether the order was paid. */
    public function cashfreeReturn(Request $request, Cashfree $cashfree)
    {
        $order = Order::where('cashfree_order_id', (string) $request->query('order_id'))->firstOrFail();
        try {
            $st = $cashfree->status($order->cashfree_order_id);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return redirect()->route('checkout')->with('error', 'We could not confirm your payment yet. If money was deducted, it will be confirmed shortly — or contact us with order '.$order->number.'.');
        }
        if ($st['paid'] && abs((float) $st['amount'] - (float) $order->total) < 0.01) {
            $this->markPaid($order, 'cashfree', $st['payment_id'] ?: $order->cashfree_order_id);
            $this->forgetCart($order);

            return redirect()->route('checkout.thanks', $order->number);
        }
        if (in_array($st['status'], ['EXPIRED', 'TERMINATED'], true)) {
            $order->update(['payment_status' => 'failed']);
        }

        return redirect()->route('checkout')->with('error', 'The payment was not completed. Your cart is still here — please try again.');
    }

    /** Cashfree confirms payments here too, in case the customer never comes back to the site. */
    public function cashfreeWebhook(Request $request, Cashfree $cashfree)
    {
        if (! $cashfree->verifyWebhook($request->getContent(), (string) $request->header('x-webhook-timestamp'), (string) $request->header('x-webhook-signature'))) {
            return response('bad signature', 400);
        }
        $cfOrderId = (string) $request->input('data.order.order_id');
        $payment = (array) $request->input('data.payment');
        if (($payment['payment_status'] ?? null) === 'SUCCESS' && $cfOrderId !== '') {
            $order = Order::where('cashfree_order_id', $cfOrderId)->first();
            if ($order && abs((float) ($payment['payment_amount'] ?? 0) - (float) $order->total) < 0.01) {
                $this->markPaid($order, 'cashfree', (string) ($payment['cf_payment_id'] ?? $cfOrderId));
            }
        }

        return response('ok');
    }

    public function thanks(Request $request, string $number, Seo $seo, Analytics $analytics)
    {
        $order = Order::where('number', $number)->with('items')->firstOrFail();
        $mine = session('placed_orders', []);
        abort_unless(in_array($order->number, $mine, true) || ($request->user() && ($request->user()->id === $order->user_id || $request->user()->is_admin)), 404);
        $seo->title('Thank you for your order')->noindex();
        // Fire the purchase event once per order, not on every refresh.
        if (! session('tracked.'.$order->number)) {
            $analytics->purchase($order);
            session(['tracked.'.$order->number => true]);
        }

        return view('thanks', compact('order'));
    }

    private function markPaid(Order $order, string $gateway, string $paymentId): void
    {
        $fresh = DB::transaction(function () use ($order, $gateway, $paymentId) {
            $o = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($o->payment_status === 'paid') {
                return false;
            }
            $o->update(['payment_status' => 'paid', $gateway.'_payment_id' => $paymentId, 'status' => $o->status === 'pending' ? 'confirmed' : $o->status]);

            return true;
        });
        if ($fresh) {
            $this->complete($order->fresh());
        }
    }

    /** Once an order is confirmed (COD placed, or online payment received). */
    private function complete(Order $order): void
    {
        if ($order->coupon_code) {
            Coupon::where('code', $order->coupon_code)->increment('used');
        }
        foreach ($order->items as $i) {
            Product::whereKey($i->product_id)->increment('sales_count', $i->qty);
        }
        app(Stock::class)->reduce($order);
        app(CartRecovery::class)->markRecovered($order);
        Invoice::assign($order);
        // WhatsApp to the customer and the shop, after the customer's page has gone out.
        NotifyOrder::dispatchAfterResponse($order->id, 'confirmed');
        // Book the Delhivery shipment right away (after the customer's page has gone out).
        if (Delhivery::enabled() && setting('delhivery.auto')) {
            CreateShipment::dispatchAfterResponse($order->id);
        }
        try {
            Mail::to($order->email)->send(new OrderPlaced($order));
            if ($admin = config('store.email')) {
                Mail::to($admin)->send(new OrderPlaced($order, true));
            }
        } catch (\Throwable $e) {
            Log::warning('Order mail failed: '.$e->getMessage());
        }
    }

    /** The customer's own browser: empty the cart and remember the order so they can see its thank-you page. */
    private function forgetCart(Order $order): void
    {
        $this->cart->clear();
        session()->push('placed_orders', $order->number);
    }

    private function codAllowed(float $total): bool
    {
        return (bool) setting('cod_enabled') && $total <= (float) setting('cod_max');
    }
}

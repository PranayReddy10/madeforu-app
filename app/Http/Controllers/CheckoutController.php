<?php

namespace App\Http\Controllers;

use App\Mail\OrderPlaced;
use App\Models\Coupon;
use App\Models\CustomerUpload;
use App\Models\Order;
use App\Models\Product;
use App\Services\Analytics;
use App\Services\Cart;
use App\Services\Razorpay;
use App\Services\Seo;
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

        return view('checkout', [
            'lines' => $lines,
            'totals' => $totals,
            'codTotals' => $this->cart->totals($lines, 'cod'),
            'states' => self::STATES,
            'prefill' => [
                'name' => $last->name ?? $user?->name, 'email' => $last->email ?? $user?->email, 'phone' => $last->phone ?? $user?->phone,
                'address_line1' => $last?->address_line1, 'address_line2' => $last?->address_line2, 'city' => $last?->city,
                'state' => $last?->state, 'pincode' => $last?->pincode,
            ],
            'razorpay' => Razorpay::enabled(),
            'codAllowed' => $this->codAllowed($totals['total']),
        ]);
    }

    public function place(Request $request, Razorpay $razorpay)
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
            'payment_method' => 'required|in:razorpay,cod',
        ], ['phone.regex' => 'Please enter a 10-digit Indian mobile number.', 'pincode.regex' => 'Please enter a valid 6-digit PIN code.']);

        $lines = $this->cart->lines();
        if ($lines->isEmpty()) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }
        $method = $data['payment_method'];
        if ($method === 'razorpay' && ! Razorpay::enabled()) {
            $method = 'cod';
        }
        $totals = $this->cart->totals($lines, $method);
        if ($method === 'cod' && ! $this->codAllowed($totals['total'])) {
            return back()->withInput()->withErrors(['payment_method' => 'Cash on delivery is not available for this order. Please pay online.']);
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

        try {
            $rp = $razorpay->createOrder((int) round($order->total * 100), $order->number, ['order' => $order->number]);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return redirect()->route('checkout')->with('error', 'We could not start the payment. Please try again, or choose cash on delivery.');
        }
        $order->update(['razorpay_order_id' => $rp['id']]);
        session(['pending_order' => $order->number]);

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
        $this->markPaid($order, $data['razorpay_payment_id']);
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
                $this->markPaid($order, $payment['id']);
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

    private function markPaid(Order $order, string $paymentId): void
    {
        $fresh = DB::transaction(function () use ($order, $paymentId) {
            $o = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($o->payment_status === 'paid') {
                return false;
            }
            $o->update(['payment_status' => 'paid', 'razorpay_payment_id' => $paymentId, 'status' => $o->status === 'pending' ? 'confirmed' : $o->status]);

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

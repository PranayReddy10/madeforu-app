<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Cashfree;
use App\Services\Razorpay;
use App\Services\Seo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Payment links for orders taken on WhatsApp/Instagram, failed online payments, or COD customers who want to
 * pay upfront. The link is a signed page on our own site that starts the normal checkout payment, so the usual
 * verify step and webhooks mark the order paid and confirm it — nothing extra to set up at Razorpay/Cashfree.
 */
class PayLinkController extends Controller
{
    public static function link(Order $order): string
    {
        return URL::signedRoute('pay.link', ['number' => $order->number]);
    }

    public function show(string $number, Seo $seo)
    {
        $order = Order::where('number', $number)->with('items')->firstOrFail();
        $seo->title('Pay for order '.$order->number)->noindex();

        return view('pay-link', ['order' => $order, 'problem' => $order->payLinkProblem(), 'amount' => $order->payableOnline()]);
    }

    public function pay(string $number, Razorpay $razorpay, Cashfree $cashfree)
    {
        $order = Order::where('number', $number)->firstOrFail();
        if ($order->payLinkProblem()) {
            return redirect(self::link($order));
        }
        $amount = $order->payableOnline();
        $gateway = in_array($order->payment_method, CheckoutController::gateways(), true) ? $order->payment_method : CheckoutController::gateways()[0];
        session(['paylink.'.$order->number => true, 'pending_order' => $order->number]);
        try {
            if ($gateway === 'cashfree') {
                $cfId = Cashfree::newOrderId($order);
                $cf = $cashfree->createOrder($order, $cfId, $amount);
                $order->forceFill(['cashfree_order_id' => $cfId])->saveQuietly();

                return view('pay-cashfree', ['order' => $order, 'sessionId' => $cf['payment_session_id'], 'mode' => Cashfree::mode()]);
            }
            $rp = $razorpay->createOrder((int) round($amount * 100), $order->number, ['order' => $order->number, 'via' => 'payment link']);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return redirect(self::link($order))->with('error', 'We could not start the payment. Please try again in a minute, or message us on WhatsApp.');
        }
        $order->forceFill(['razorpay_order_id' => $rp['id']])->saveQuietly();

        return view('pay', ['order' => $order, 'rp' => $rp]);
    }
}

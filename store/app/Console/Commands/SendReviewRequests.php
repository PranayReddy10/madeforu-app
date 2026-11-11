<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReviewRequestController;
use App\Mail\ReviewRequest;
use App\Models\Order;
use App\Services\WhatsApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendReviewRequests extends Command
{
    protected $signature = 'store:review-requests';

    protected $description = 'Ask customers for a review a few days after their order is delivered';

    public function handle(WhatsApp $wa): int
    {
        if (! setting('reviews.request_enabled', true)) {
            return self::SUCCESS;
        }
        $days = max(0, (int) setting('reviews.request_days', 2));
        $orders = Order::where('status', 'delivered')->whereNull('review_requested_at')
            ->whereNotNull('delivered_at')->where('delivered_at', '<=', now()->subDays($days))
            ->where('delivered_at', '>=', now()->subDays(30))->with('items.product')->get();
        foreach ($orders as $order) {
            $link = ReviewRequestController::link($order);
            try {
                Mail::to($order->email)->send(new ReviewRequest($order, $link));
            } catch (\Throwable $e) {
                $this->warn($order->number.': '.$e->getMessage());
            }
            if (WhatsApp::enabled() && ($template = setting('reviews.whatsapp_template')) && ($to = WhatsApp::number($order->phone))) {
                $wa->sendForOrder($order, 'review_request', $to, $template, [Str::before(trim($order->name), ' '), $order->number, $link]);
            }
            $order->forceFill(['review_requested_at' => now()])->saveQuietly();
        }
        $this->info('Review requests sent: '.$orders->count());

        return self::SUCCESS;
    }
}

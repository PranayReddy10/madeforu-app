<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Review;
use App\Services\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** "How was your gift?" — one page to review everything in a delivered order, linked from email/WhatsApp. */
class ReviewRequestController extends Controller
{
    public static function link(Order $order): string
    {
        return URL::temporarySignedRoute('review.order', now()->addDays(60), ['number' => $order->number]);
    }

    private function products(Order $order)
    {
        $done = Review::where('order_id', $order->id)->pluck('product_id')->all();

        return $order->items->pluck('product')->filter()->unique('id')->reject(fn ($p) => in_array($p->id, $done, true))->values();
    }

    public function show(Request $request, string $number, Seo $seo)
    {
        abort_unless($request->hasValidSignature(), 403, 'This review link has expired.');
        $order = Order::where('number', $number)->with('items.product.images')->firstOrFail();
        $seo->title('Review your order')->noindex();

        return view('review-order', ['order' => $order, 'products' => $this->products($order)]);
    }

    public function store(Request $request, string $number)
    {
        abort_unless($request->hasValidSignature(), 403, 'This review link has expired.');
        $order = Order::where('number', $number)->with('items.product')->firstOrFail();
        $request->validate([
            'name' => 'required|string|max:80',
            'reviews' => 'required|array',
            'reviews.*.rating' => 'nullable|integer|between:1,5',
            'reviews.*.body' => 'nullable|string|max:2000',
            'photos' => 'nullable|array',
            'photos.*' => 'nullable|array|max:3',
            'photos.*.*' => 'image|max:10240',
        ]);
        $saved = 0;
        foreach ($this->products($order) as $product) {
            $r = $request->input('reviews.'.$product->id, []);
            if (empty($r['rating'])) {
                continue;   // skipped this one
            }
            Review::create([
                'product_id' => $product->id, 'order_id' => $order->id, 'user_id' => $order->user_id,
                'name' => $request->input('name'), 'rating' => (int) $r['rating'], 'body' => $r['body'] ?? null,
                'photos' => Review::storePhotos($request->file('photos.'.$product->id, [])) ?: null,
                'is_verified' => true,
                'is_approved' => setting('reviews.auto_approve') && (int) $r['rating'] >= 4,
            ]);
            $saved++;
        }
        if (! $saved) {
            return back()->withErrors(['reviews' => 'Please give at least one star rating.']);
        }

        return redirect()->route('home')->with('status', 'Thank you for your review'.($saved > 1 ? 's' : '').'! 💝');
    }
}

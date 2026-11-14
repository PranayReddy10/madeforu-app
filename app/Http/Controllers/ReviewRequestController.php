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
    /** Signed review link; $rate pre-picks the stars (from the Track order page). */
    public static function link(Order $order, ?int $rate = null): string
    {
        return URL::temporarySignedRoute('review.order', now()->addDays(60), array_filter(['number' => $order->number, 'rate' => $rate]));
    }

    /** Products in the order not reviewed yet. */
    public static function products(Order $order)
    {
        $done = Review::where('order_id', $order->id)->pluck('product_id')->all();

        return $order->items->pluck('product')->filter()->unique('id')->reject(fn ($p) => in_array($p->id, $done, true))->values();
    }

    public function show(Request $request, string $number, Seo $seo)
    {
        abort_unless($request->hasValidSignature(), 403, 'This review link has expired.');
        $order = Order::where('number', $number)->with('items.product.images')->firstOrFail();
        $seo->title('Review your order')->noindex();

        $rate = (int) $request->query('rate');

        return view('review-order', ['order' => $order, 'products' => self::products($order), 'rate' => $rate >= 1 && $rate <= 5 ? $rate : null]);
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
        $saved = [];
        foreach (self::products($order) as $product) {
            $r = $request->input('reviews.'.$product->id, []);
            if (empty($r['rating'])) {
                continue;   // skipped this one
            }
            $saved[] = Review::create([
                'product_id' => $product->id, 'order_id' => $order->id, 'user_id' => $order->user_id,
                'name' => $request->input('name'), 'rating' => (int) $r['rating'], 'body' => $r['body'] ?? null,
                'photos' => Review::storePhotos($request->file('photos.'.$product->id, [])) ?: null,
                'is_verified' => true,
                'is_approved' => setting('reviews.auto_approve') && (int) $r['rating'] >= 4,
            ]);
        }
        if (! $saved) {
            return back()->withErrors(['reviews' => 'Please give at least one star rating.']);
        }

        return self::thanks($saved, collect($saved)->every('is_approved'));
    }

    /**
     * After a review: say thanks, and ask to post it on Google too (Admin → Settings → Reviews → Google review link).
     * Shown to every reviewer whatever the stars — Google's rules forbid asking only the happy ones.
     *
     * @param  array<int, Review>  $reviews
     */
    public static function thanks(array $reviews, bool $published)
    {
        $reviews = collect($reviews)->each->loadMissing('product');
        $text = $reviews->count() === 1
            ? (string) $reviews->first()->body
            : $reviews->filter(fn ($r) => filled($r->body))->map(fn ($r) => $r->product?->name.': '.$r->body)->implode("\n");

        return redirect()->route('review.thanks')->with('review_thanks', [
            'count' => $reviews->count(),
            'published' => $published,
            'text' => trim($text),
            'stars' => (int) round($reviews->avg('rating')),
        ]);
    }

    public function done(Seo $seo)
    {
        $seo->title('Thank you for your review')->noindex();
        $google = (string) setting('reviews.google_url');

        return view('review-thanks', [
            'r' => session('review_thanks'),
            'google' => preg_match('#^https://#i', $google) ? $google : null,
        ]);
    }
}

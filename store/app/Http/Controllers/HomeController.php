<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Services\Seo;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    /** Questions shoppers ask before ordering a personalised gift; shown on the home page and given FAQ schema. */
    public static function faqs(): array
    {
        $free = money(setting('free_shipping_over'));

        return [
            ['How do I personalise my gift?', 'Open any product, upload your photos straight from your phone gallery and add the names, dates or message you want. You’ll see exactly what you’ve added before you pay.'],
            ['How long does delivery take?', 'Every gift is made to order, which usually takes 2–5 business days. After dispatch, Delhivery delivers in 2–4 business days to metro cities and 4–7 days elsewhere in India, with tracking on every order.'],
            ['Is shipping free?', 'Shipping is free on orders over '.$free.'. Below that, a small shipping charge is shown at checkout before you pay.'],
            ['Can I pay cash on delivery?', setting('cod_enabled') ? 'Yes — cash on delivery is available on most PIN codes. You can also pay securely by UPI, cards or netbanking.' : 'We accept UPI, cards and netbanking through secure payment gateways.'],
            ['What if my gift arrives damaged?', 'Message us within 48 hours of delivery with a photo or unboxing video and your order number. We’ll send a free replacement or refund you.'],
            ['Do you take bulk or corporate orders?', 'Yes! Tell us the product, quantity and date on WhatsApp or through the Contact page and we’ll send you a quote.'],
        ];
    }

    public function index(Seo $seo)
    {
        $seo->title(setting('home_title') ?: config('store.name').' - Personalised Gifts Online India | Custom Photo Gifts')
            ->description(setting('home_description') ?: 'Personalised photo gifts made for you: custom frames, mugs, cushions, keychains and more. Upload your photos, we make it and deliver across India.')
            ->canonical(url('/'));
        foreach ($seo->siteSchema() as $s) {
            $seo->addSchema($s);
        }
        $seo->addSchema([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => collect(self::faqs())->map(fn ($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]])->all(),
        ]);

        $with = ['images', 'variants', 'primaryCategory', 'customizationFields'];
        $shownIds = Category::withShopProductsIds();
        $categories = Category::active()->where('show_on_home', true)->whereNull('parent_id')->whereIn('id', $shownIds)->withShopCount()->orderBy('position')->orderBy('name')->get();

        // The biggest categories get their own row of products, so more of the range is on the first page.
        $spotlights = Category::active()->whereIn('id', $shownIds)->withShopCount()->orderByDesc('products_count')->take(3)->get()
            ->filter(fn ($c) => $c->products_count >= 2)
            ->map(fn ($c) => (object) ['category' => $c, 'products' => $c->products()->active()->with($with)->orderByDesc('sales_count')->orderByDesc('views')->take(8)->get()])
            ->values();

        $deals = Product::active()->with($with)->whereNotNull('sale_price')->where('sale_price', '>', 0)->whereColumn('sale_price', '<', 'price')
            ->orderByRaw('(price - sale_price) / price desc')->take(10)->get();

        // "Gifts under ₹X" shortcuts, only for amounts that have something to show.
        $priceTiles = Cache::remember('home.price-tiles', 600, fn () => collect([199, 299, 499, 999])
            ->map(fn ($max) => ['max' => $max, 'count' => Product::active()->whereRaw('coalesce(nullif(sale_price, 0), price) <= ?', [$max])->count()])
            ->filter(fn ($t) => $t['count'] > 0)->values()->all());

        $approved = Review::where('is_approved', true);

        return view('home', [
            'banners' => Banner::where('is_active', true)->orderBy('position')->get(),
            'categories' => $categories,
            'featured' => Product::active()->with($with)->where('is_featured', true)->latest()->take(12)->get(),
            'bestsellers' => Product::active()->with($with)->orderByDesc('sales_count')->orderByDesc('views')->take(12)->get(),
            'newest' => Product::active()->with($with)->latest()->take(12)->get(),
            'deals' => $deals,
            'spotlights' => $spotlights,
            'priceTiles' => $priceTiles,
            'reviews' => (clone $approved)->where('rating', '>=', 4)->whereNotNull('body')->with('product')->latest()->take(8)->get(),
            'rating' => ['avg' => round((float) (clone $approved)->avg('rating'), 1), 'count' => (clone $approved)->count()],
            'faqs' => self::faqs(),
        ]);
    }
}

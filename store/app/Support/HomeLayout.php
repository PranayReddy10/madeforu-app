<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * The home page as an ordered list of sections, edited in Admin → Home page and saved as one setting.
 * Each section: ['id' => …, 'type' => …, 'on' => bool, …its own texts and options].
 * Until it is edited, default() gives the standard MadeForU home page.
 */
class HomeLayout
{
    /** type => [name in admin, can be added more than once, short help] */
    public const TYPES = [
        'slider' => ['Top slider', false, 'Slides come from Admin → Banners.'],
        'usp' => ['Benefits strip', false, 'Hand-made, free shipping, COD, secure payments, review rating.'],
        'categories' => ['Shop by category', false, 'Categories ticked “Show on home page” in Admin → Categories.'],
        'products' => ['Product row', true, 'A row of products: bestsellers, newest, featured, on sale, one category, or products you pick.'],
        'price_tiles' => ['Gifts for every budget', false, 'Coloured “Gifts under ₹X” tiles.'],
        'category_row' => ['Category spotlight', true, 'One category with its products, beside a picture of it.'],
        'offers' => ['Offers', false, 'Products with a sale price, on a dark panel.'],
        'promo' => ['Promo banner', true, 'Your own picture with a title and button — a sale, a new category, a festival.'],
        'steps' => ['How it works (3 steps)', false, ''],
        'custom_band' => ['Custom & bulk orders banner', false, 'With WhatsApp and Contact buttons.'],
        'reviews' => ['Customer reviews', false, 'Approved 4★ and 5★ reviews.'],
        'instagram' => ['Instagram', false, 'Your latest posts (Settings → Instagram), or a follow band.'],
        'why' => ['Why shop with us (4 points)', false, ''],
        'faq' => ['Questions & answers', false, 'Also shown to Google as FAQ.'],
        'text' => ['Text block', true, 'Headline and text — good for SEO.'],
    ];

    public const SOURCES = ['bestsellers' => 'Bestsellers', 'newest' => 'Newest', 'featured' => 'Featured (ticked on the product)', 'sale' => 'On sale', 'category' => 'From one category', 'manual' => 'Products I pick'];

    public static function get(): array
    {
        $saved = json_decode((string) setting('home.layout'), true);

        return is_array($saved) && $saved ? self::clean($saved) : self::default();
    }

    public static function save(array $sections): void
    {
        Setting::put(['home.layout' => json_encode(array_values(self::clean($sections)), JSON_UNESCAPED_UNICODE)]);
    }

    public static function reset(): void
    {
        Setting::put(['home.layout' => null]);
    }

    public static function make(string $type, array $values = []): array
    {
        $blank = match ($type) {
            'products' => ['title' => 'Bestsellers', 'subtitle' => '', 'source' => 'bestsellers', 'category_id' => null, 'product_ids' => [], 'limit' => 12],
            'category_row' => ['category_id' => null],
            'promo' => ['image' => '', 'mobile_image' => '', 'title' => '', 'text' => '', 'button' => 'Shop now', 'link' => '/shop/'],
            'text' => ['title' => '', 'body' => ''],
            'categories' => ['title' => 'Shop by category', 'subtitle' => 'Every one made with your photos'],
            'price_tiles' => ['title' => 'Gifts for every budget', 'subtitle' => 'Personalised, without the guesswork', 'amounts' => '199, 299, 499, 999'],
            'offers' => ['eyebrow' => 'Limited-time prices', 'title' => 'Offers you’ll love'],
            'steps' => ['eyebrow' => 'Simple as 1-2-3', 'title' => 'Made for you in three steps',
                's1_title' => 'Choose your gift', 's1_text' => 'Magnets, mugs, bottles, keychains, badges — pick the size and style.',
                's2_title' => 'Upload your photos', 's2_text' => 'Straight from your phone gallery. Add names, dates or a message.',
                's3_title' => 'We craft & deliver', 's3_text' => 'Made and checked by hand, packed with care and shipped to your door with tracking.',
                'button' => 'Start personalising', 'link' => '/shop/'],
            'custom_band' => ['eyebrow' => 'Custom & bulk orders', 'title' => 'Have an idea of your own?',
                'text' => 'Send us your photo and idea on WhatsApp — birthdays, weddings, return gifts or company merch. We’ll suggest options and a price.'],
            'reviews' => ['title' => 'Happy customers'],
            'instagram' => ['title' => 'Follow us on Instagram'],
            'why' => ['w1_title' => 'Handmade in '.config('store.city'), 'w1_text' => 'Every piece made to order by our own team.',
                'w2_title' => 'Checked before it ships', 'w2_text' => 'We look over your photos and details as we make it.',
                'w3_title' => 'Tracked delivery', 'w3_text' => 'Delhivery to every corner of India, with live tracking.',
                'w4_title' => 'Damaged? We fix it', 'w4_text' => 'Free replacement or refund if it arrives broken.'],
            'faq' => ['eyebrow' => 'Good to know', 'title' => 'Questions, answered', 'items' => self::defaultFaqs()],
            default => [],
        };

        return ['id' => $values['id'] ?? Str::lower(Str::random(8)), 'type' => $type, 'on' => true] + array_replace($blank, array_intersect_key($values, $blank + ['on' => 1]));
    }

    /** Today's MadeForU home page, section by section. */
    public static function default(): array
    {
        return [
            self::make('slider'), self::make('usp'), self::make('categories'),
            self::make('products', ['title' => 'Bestsellers', 'subtitle' => 'Loved by our customers', 'source' => 'bestsellers']),
            self::make('price_tiles'),
            self::make('category_row'),
            self::make('offers'),
            self::make('steps'),
            self::make('category_row'),
            self::make('category_row'),
            self::make('custom_band'), self::make('reviews'),
            self::make('products', ['title' => 'Just arrived', 'subtitle' => 'New ways to say it', 'source' => 'newest']),
            self::make('products', ['title' => 'Handpicked for you', 'subtitle' => 'Our favourite pieces this season', 'source' => 'featured']),
            self::make('why'), self::make('instagram'), self::make('faq'),
            self::make('text', ['title' => 'Personalised gifts online in India', 'body' => '<p>'.config('store.name').' makes custom photo gifts for birthdays, anniversaries, weddings, Valentine\'s Day, Raksha Bandhan and every moment in between. Upload your photos, add a name or a message, and we make each gift by hand in '.config('store.city').' after you order. Choose from personalised photo magnets, custom mugs, bottles, keychains, badges and more, with cash on delivery and tracked shipping across India.</p>']),
        ];
    }

    public static function defaultFaqs(): array
    {
        $free = money(setting('free_shipping_over'));

        return [
            ['q' => 'How do I personalise my gift?', 'a' => 'Open any product, upload your photos straight from your phone gallery and add the names, dates or message you want. You’ll see exactly what you’ve added before you pay.'],
            ['q' => 'How long does delivery take?', 'a' => 'Every gift is made to order, which usually takes 2–5 business days. After dispatch, Delhivery delivers in 2–4 business days to metro cities and 4–7 days elsewhere in India, with tracking on every order.'],
            ['q' => 'Is shipping free?', 'a' => 'Shipping is free on orders over '.$free.'. Below that, a small shipping charge is shown at checkout before you pay.'],
            ['q' => 'Can I pay cash on delivery?', 'a' => 'Yes — cash on delivery is available on most PIN codes. You can also pay securely by UPI, cards or netbanking.'],
            ['q' => 'What if my gift arrives damaged?', 'a' => 'Message us within 48 hours of delivery with a photo or unboxing video and your order number. We’ll send a free replacement or refund you.'],
            ['q' => 'Do you take bulk or corporate orders?', 'a' => 'Yes! Tell us the product, quantity and date on WhatsApp or through the Contact page and we’ll send you a quote.'],
        ];
    }

    /** Keep only known types and fields, with sensible limits; single-use sections appear once. */
    public static function clean(array $sections): array
    {
        $out = [];
        $seen = [];
        foreach ($sections as $s) {
            $type = $s['type'] ?? null;
            if (! is_string($type) || ! isset(self::TYPES[$type]) || (! self::TYPES[$type][1] && isset($seen[$type]))) {
                continue;
            }
            $seen[$type] = true;
            $id = preg_match('/^[a-z0-9]{4,16}$/', (string) ($s['id'] ?? '')) ? $s['id'] : null;
            $base = self::make($type, ['id' => $id]);
            $clean = $base;
            $clean['on'] = filter_var($s['on'] ?? false, FILTER_VALIDATE_BOOLEAN);
            foreach ($base as $k => $default) {
                if (in_array($k, ['id', 'type', 'on'], true) || ! array_key_exists($k, $s)) {
                    continue;
                }
                $v = $s[$k];
                $clean[$k] = match (true) {
                    $k === 'items' => collect((array) $v)->map(fn ($i) => ['q' => Str::limit(trim((string) ($i['q'] ?? '')), 300, ''), 'a' => Str::limit(trim((string) ($i['a'] ?? '')), 2000, '')])
                        ->filter(fn ($i) => $i['q'] !== '' && $i['a'] !== '')->values()->all(),
                    $k === 'product_ids' => collect(is_array($v) ? $v : explode(',', (string) $v))->map(fn ($x) => (int) $x)->filter()->unique()->take(24)->values()->all(),
                    $k === 'category_id' => (int) $v ?: null,
                    $k === 'limit' => max(2, min(24, (int) $v ?: 12)),
                    $k === 'source' => array_key_exists($v, self::SOURCES) ? $v : 'bestsellers',
                    $k === 'body' => Str::limit((string) $v, 20000, ''),
                    default => Str::limit(trim((string) $v), 600, ''),
                };
            }
            $out[] = $clean;
        }

        return $out;
    }
}

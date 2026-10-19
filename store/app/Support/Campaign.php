<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A festival campaign (Diwali, Rakhi, Valentine's…), set in Admin → Campaign:
 * a countdown strip on every page, an "arrives before Diwali?" note on products,
 * a landing page with the chosen gifts, and a coupon that ends with the campaign.
 */
class Campaign
{
    /** Ready-made festivals: name, festival date, order-by date, coupon. */
    public static function presets(): array
    {
        return [
            'diwali' => ['Diwali', '2026-11-08', '2026-11-02', 'DIWALI10', 'Light up their Diwali with a gift made from your photos'],
            'christmas' => ['Christmas', '2026-12-25', '2026-12-18', 'XMAS10', 'Personalised Christmas gifts they’ll keep for years'],
            'valentines' => ['Valentine’s Day', '2027-02-14', '2027-02-07', 'LOVE10', 'Say it with your photos this Valentine’s Day'],
        ];
    }

    public static function active(): bool
    {
        return (bool) setting('campaign.enabled') && setting('campaign.name') && self::festival() && today()->lte(self::festival());
    }

    public static function name(): string
    {
        return (string) setting('campaign.name');
    }

    public static function festival(): ?Carbon
    {
        return self::date('campaign.festival');
    }

    /** Last day to order for delivery before the festival (end of that day). */
    public static function deadline(): ?Carbon
    {
        return self::date('campaign.deadline')?->endOfDay();
    }

    public static function beforeDeadline(): bool
    {
        return ($d = self::deadline()) !== null && now()->lte($d);
    }

    public static function coupon(): ?string
    {
        return setting('campaign.coupon') ? strtoupper((string) setting('campaign.coupon')) : null;
    }

    public static function discount(): float
    {
        return (float) setting('campaign.discount');
    }

    public static function minOrder(): float
    {
        return (float) setting('campaign.min_order');
    }

    public static function headline(): string
    {
        return (string) (setting('campaign.headline') ?: self::name().' gifts made from your photos');
    }

    public static function slug(): string
    {
        return \Illuminate\Support\Str::slug(setting('campaign.slug') ?: self::name().' gifts') ?: 'festival-gifts';
    }

    public static function url(): string
    {
        return url(self::slug()).'/';
    }

    /** Products for the landing page: the chosen category, else featured + bestsellers. */
    public static function products(int $limit = 24): Collection
    {
        $q = Product::active()->with(['images', 'variants', 'categories']);
        if ($id = (int) setting('campaign.category')) {
            $ids = Category::where('id', $id)->orWhere('parent_id', $id)->pluck('id');
            $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $ids));
        } else {
            $q->orderByDesc('is_featured')->orderByDesc('sales_count');
        }

        return $q->limit($limit)->get();
    }

    /**
     * Can this product still arrive before the festival if ordered now?
     *
     * @return array{ok: bool, text: string}|null
     */
    public static function productNote(Product $p): ?array
    {
        if (! self::active()) {
            return null;
        }
        $arrives = now()->addWeekdays(max(1, (int) $p->production_days))->addDays(6);
        $fest = self::festival();
        if ($arrives->lte($fest->copy()->endOfDay())) {
            return ['ok' => true, 'text' => 'Order now — arrives before '.self::name().' ('.$fest->format('j M').')'];
        }

        return ['ok' => false, 'text' => 'Ordered today, this may arrive after '.self::name().'. Message us on WhatsApp for a rush order.'];
    }

    private static function date(string $key): ?Carbon
    {
        try {
            return setting($key) ? Carbon::parse((string) setting($key))->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Stock keeping units, made automatically when a product or variation has none:
 *   product   MFU-MAG-007        (MFU – category code – running number per category)
 *   variation MFU-MAG-007-RND    (+ a short code per option, e.g. Shape=Round → RND, Size=A4 → A4)
 * SKUs typed by hand (or imported from WooCommerce) are never changed.
 */
class Sku
{
    public const PREFIX = 'MFU';

    /** Words → short codes for MadeForU's kinds of gifts; anything else uses its first letters. */
    private const CODES = [
        'magnet' => 'MAG', 'mug' => 'MUG', 'keychain' => 'KEY', 'key chain' => 'KEY', 'key-chain' => 'KEY', 'badge' => 'BDG',
        'bottle' => 'BTL', 'combo' => 'CMB', 'hamper' => 'HMP', 'frame' => 'FRM', 'tote' => 'BAG', 'pouch' => 'BAG', 'bag' => 'BAG',
        't-shirt' => 'TSH', 'tshirt' => 'TSH', 'shirt' => 'TSH', 'kurta' => 'KRT', 'lamp' => 'LMP', 'cushion' => 'CSN', 'pillow' => 'CSN',
        'calendar' => 'CAL', 'clock' => 'CLK', 'puzzle' => 'PZL', 'raw material' => 'RAW', 'sticker' => 'STK', 'card' => 'CRD',
    ];

    public static function forProduct(Product $p): string
    {
        $code = self::categoryCode($p);
        $base = self::PREFIX.'-'.$code.'-';
        $n = Product::where('sku', 'like', $base.'%')->pluck('sku')
            ->map(fn ($s) => preg_match('/^'.preg_quote($base, '/').'(\d+)$/', $s, $m) ? (int) $m[1] : 0)->max() ?? 0;
        do {
            $sku = $base.str_pad((string) ++$n, 3, '0', STR_PAD_LEFT);
        } while (Product::where('sku', $sku)->where('id', '!=', $p->id)->exists());

        return $sku;
    }

    public static function forVariant(ProductVariant $v): string
    {
        $parent = $v->product?->sku ?: ($v->product ? self::forProduct($v->product) : self::PREFIX);
        $codes = collect($v->options ?? [])->map(fn ($val) => self::optionCode((string) $val))->filter()->implode('-');
        $base = $parent.'-'.($codes ?: 'V');
        $sku = $base;
        $i = 1;
        while (ProductVariant::where('sku', $sku)->where('id', '!=', $v->id)->exists()) {
            $sku = $base.'-'.++$i;
        }

        return $sku;
    }

    public static function categoryCode(Product $p): string
    {
        $cat = $p->primary_category_id ? Category::find($p->primary_category_id) : $p->categories()->first();
        // A sub-category (e.g. "Square magnets") is most specific; fall back to its parent, then the product name.
        $texts = array_filter([$cat?->name, $cat?->parent_id ? Category::find($cat->parent_id)?->name : null, $p->name]);
        // Gift sets first: "Mug + Magnet Combo" is a combo, not a mug.
        foreach ($texts as $text) {
            if (preg_match('/\bhamper/i', $text)) {
                return 'HMP';
            }
            if (preg_match('/\bcombo|\bgift set\b|\bmemory box\b/i', $text)) {
                return 'CMB';
            }
        }
        foreach ($texts as $text) {
            $t = mb_strtolower($text);
            foreach (self::CODES as $word => $code) {
                if (str_contains($t, $word)) {
                    return $code;
                }
            }
        }
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', Str::ascii($cat?->name ?: $p->name)));

        return substr($letters ?: 'GEN', 0, 3);
    }

    /** "Round" → RND, "Two sides" → TWS, "A4" → A4, "2x2 Inch" → 2X2, "Pack of 6" → P6. */
    public static function optionCode(string $value): string
    {
        $v = strtoupper(trim(Str::ascii($value)));
        if (preg_match('/^(?:PACK|SET)[- ]*(?:OF )?(\d+)$/', $v, $m)) {
            return 'P'.$m[1];
        }
        if (preg_match('/\d/', $v)) {
            return substr(preg_replace('/[^A-Z0-9]/', '', strtok($v, ' ')), 0, 6);
        }
        $words = preg_split('/\s+/', preg_replace('/[^A-Z ]/', '', $v));
        if (count($words) > 1) {
            return substr(implode('', array_map(fn ($w) => $w[0] ?? '', $words)), 0, 4);
        }
        $w = $words[0] ?? '';
        // First letter, then the consonants: Round → RND, Heart → HRT, Black → BLC
        $short = ($w[0] ?? '').preg_replace('/[AEIOU]/', '', substr($w, 1));

        return substr($short ?: $w, 0, 3);
    }

    /**
     * Give every product and variation without a SKU one. A SKU used twice counts as missing too:
     * the first product keeps it, later ones (and options that copy their product's SKU) get their own.
     *
     * @return int how many were made
     */
    public static function fillMissing(): int
    {
        $n = 0;
        $seen = [];
        foreach (Product::orderBy('id')->get() as $p) {
            $sku = strtoupper(trim((string) $p->sku));
            if ($sku === '' || isset($seen[$sku])) {
                $p->forceFill(['sku' => $sku = self::forProduct($p)])->saveQuietly();
                $n++;
            }
            $seen[$sku] = true;
        }
        foreach (ProductVariant::with('product')->orderBy('id')->get() as $v) {
            $sku = strtoupper(trim((string) $v->sku));
            if ($sku === '' || isset($seen[$sku])) {
                $v->forceFill(['sku' => $sku = self::forVariant($v)])->saveQuietly();
                $n++;
            }
            $seen[$sku] = true;
        }

        return $n;
    }

    /** How many products and variations have no SKU, or share one. */
    public static function missingCount(): int
    {
        $skus = Product::pluck('sku')->concat(ProductVariant::pluck('sku'))->map(fn ($s) => strtoupper(trim((string) $s)));

        return $skus->filter(fn ($s) => $s === '')->count() + $skus->filter()->countBy()->sum(fn ($c) => $c - 1);
    }
}

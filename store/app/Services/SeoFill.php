<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Fills product and category SEO fields from what the shop already knows (name, categories,
 * description). Only fields that are empty or fail the SEO check are written; a good title or
 * description someone typed by hand is kept.
 */
class SeoFill
{
    private const BRAND = 'MadeForU';

    /** @return array<string, string> the fields that were changed */
    public function product(Product $p): array
    {
        $p->loadMissing('images', 'categories');
        [$core, $kw] = self::coreAndKeyword($p->name);
        $changes = [];

        $focus = trim((string) $p->focus_keyword);
        if ($focus === '') {
            $changes['focus_keyword'] = $focus = self::keywordForSlug($p->name, (string) $p->slug, $kw);
        }
        $focusLc = mb_strtolower($focus);

        $title = (string) $p->meta_title;
        $tl = mb_strlen($title);
        if ($tl < 30 || $tl > 60 || ! str_contains(mb_strtolower($title), $focusLc)) {
            $changes['meta_title'] = self::title(str_contains(mb_strtolower($core), $focusLc) ? $core : Str::title($focus));
        }

        $desc = (string) $p->meta_description;
        $dl = mb_strlen($desc);
        if ($dl < 70 || $dl > 160 || ! str_contains(mb_strtolower($desc), $focusLc)) {
            $changes['meta_description'] = self::description(str_contains(mb_strtolower($core), $focusLc) ? $core : Str::ucfirst($focus),
                (string) ($p->short_description ?: $p->description));
        }

        if (trim((string) $p->meta_keywords) === '') {
            $changes['meta_keywords'] = self::keywords($p, $focus);
        }

        if ($changes) {
            $p->forceFill($changes)->save();
        }
        foreach ($p->images as $i => $img) {
            if (trim((string) $img->alt) === '') {
                $img->update(['alt' => $p->images->count() > 1 ? $core.' – photo '.($i + 1) : $core]);
                $changes['image_alt'] = 'filled';
            }
        }

        return $changes;
    }

    /** @return array<string, string> */
    public function category(Category $c): array
    {
        $changes = [];
        $name = trim($c->name);
        $tl = mb_strlen((string) $c->meta_title);
        if ($tl < 30 || $tl > 60) {
            $changes['meta_title'] = self::title('Personalised '.$name, 'Custom '.$name.' Online');
        }
        $dl = mb_strlen((string) $c->meta_description);
        if ($dl < 70 || $dl > 160) {
            $changes['meta_description'] = self::cut('Shop personalised '.mb_strtolower($name).' made with your own photos and names. Handmade in Hyderabad, delivered across India'
                .(setting('cod_enabled') ? ' with cash on delivery.' : '.'), 160);
        }
        if ($changes) {
            $c->forceFill($changes)->save();
        }

        return $changes;
    }

    /**
     * "Personalized Coffee Mug | Custom Photo Mug | Printed Ceramic Mug Gift" → ["Personalized Coffee Mug", "personalized coffee mug"]
     * "Personalized Photo Magnet – 2×2 Inch Square (Set of 2)" → ["Personalized Photo Magnet", "personalized photo magnet"]
     *
     * @return array{0: string, 1: string}
     */
    public static function coreAndKeyword(string $name): array
    {
        $first = trim(preg_split('/\s+[|–—-]\s+|\s*\|\s*/u', $name)[0] ?? $name);
        $clean = preg_replace([
            '/\([^)]*\)/u',                                                       // (Set of 2)
            '/\b\d+(\.\d+)?\s*[x×]\s*\d+(\.\d+)?\s*(inch(es)?|in|cm|mm)?\b/iu',   // 2x2 Inch, 3.5×2.5
            '/\b(set|pack) of \d+\b/iu',
            '/\b\d+\s*(inch(es)?|cm|mm|ml|pcs?|pieces?)\b/iu',
        ], ' ', $first);
        $words = array_values(array_filter(preg_split('/\s+/u', trim((string) preg_replace('/[^\pL\pN&\s\'-]/u', ' ', $clean)))));
        $core = implode(' ', array_slice($words, 0, 6)) ?: trim($first);
        $kw = mb_strtolower(implode(' ', array_slice($words, 0, 4))) ?: mb_strtolower($core);

        return [$core, $kw];
    }

    /**
     * The URL can't change (old WooCommerce links), so pick a keyword the URL already contains:
     * e.g. "custom 44mm round badge" for /product/custom-44mm-round-badge-…/ rather than "custom round badge".
     */
    public static function keywordForSlug(string $name, string $slug, string $fallback): string
    {
        $first = trim(preg_split('/\s+[|–—-]\s+|\s*\|\s*/u', $name)[0] ?? $name);
        $raw = array_values(array_filter(preg_split('/\s+/u', trim((string) preg_replace('/[^\pL\pN\s]/u', ' ', $first)))));
        $clean = explode(' ', $fallback);
        $candidates = [];
        foreach ([$raw, $clean] as $words) {
            for ($n = min(5, count($words)); $n >= 2; $n--) {
                for ($start = 0; $start + $n <= count($words); $start++) {
                    $candidates[] = mb_strtolower(implode(' ', array_slice($words, $start, $n)));
                }
            }
        }
        foreach ($candidates as $c) {
            if (str_contains($slug, Str::slug($c)) && mb_strlen($c) >= 8) {
                return $c;
            }
        }

        return $fallback;
    }

    /** 30–60 characters, brand at the end when it fits. */
    public static function title(string $core, string $extra = 'Buy Online in India'): string
    {
        foreach (["{$core} – {$extra} | ".self::BRAND, "{$core} | ".self::BRAND, "{$core} – Personalised Gift | ".self::BRAND] as $t) {
            if (mb_strlen($t) >= 30 && mb_strlen($t) <= 60) {
                return $t;
            }
        }

        return mb_strlen($core) > 60 ? self::cut($core, 60) : self::cut($core.' – Personalised Gift Online', 60);
    }

    /** 70–160 characters: what it is, one line from the product text, and why order here. */
    public static function description(string $core, string $text): string
    {
        $sentence = trim((string) Str::of(html_entity_decode(strip_tags($text)))->squish()->before('. '));
        $sentence = $sentence !== '' && mb_strtolower($sentence) !== mb_strtolower($core) ? rtrim($sentence, '.!').'.' : '';
        $free = (float) setting('free_shipping_over');
        $cod = setting('cod_enabled') ? ', COD available' : '';
        $whys = ['Handmade in Hyderabad'.$cod.($free > 0 ? ', free shipping over ₹'.number_format($free) : '').'.', 'Handmade in Hyderabad'.$cod.'.', ''];
        $lead = "Buy {$core} online at ".self::BRAND.'.';
        // The product's own line first (so no two products share a description), then as much "why us" as fits.
        foreach ($whys as $why) {
            $d = trim("{$lead} {$sentence} {$why}");
            if (mb_strlen($d) <= 160) {
                break;
            }
        }
        if (mb_strlen($d) > 160) {
            $d = $sentence !== '' && mb_strlen($sentence) <= 160 && str_contains(mb_strtolower($sentence), mb_strtolower(explode(' ', $core)[0] ?? '')) && mb_strlen("{$core}: {$sentence}") <= 160
                ? "{$core}: {$sentence}" : self::cut("{$lead} {$sentence}", 160);
        }
        if (mb_strlen($d) < 70) {
            $d .= ' Personalised with your photos, delivered across India.';
        }

        return self::cut($d, 160);
    }

    /** Focus keyword, the other name parts, categories and gift phrases. */
    public static function keywords(Product $p, string $focus): string
    {
        $parts = collect(preg_split('/\s*[|–—]\s*|\s+-\s+/u', $p->name))->map(fn ($s) => self::coreAndKeyword($s)[1]);
        $cats = $p->categories->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)));
        $list = collect([$focus])->concat($parts)
            ->concat($cats->map(fn ($n) => 'personalised '.$n))
            ->concat($cats->map(fn ($n) => 'custom '.$n.' online india'))
            ->push('personalised gifts', 'photo gifts online india')
            ->map(fn ($s) => trim(preg_replace('/\s+/u', ' ', (string) $s)))
            ->filter(fn ($s) => mb_strlen($s) > 2)->unique()->take(12);

        return self::cut($list->implode(', '), 500);
    }

    private static function cut(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $s = mb_substr($s, 0, $max);

        return rtrim(mb_substr($s, 0, (int) mb_strrpos($s, ' ') ?: $max), ' ,.;:–—-|&');
    }
}

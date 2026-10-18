<?php

namespace App\Services;

use App\Models\Product;

/**
 * The parcel for a set of items: actual weight, packed size and the weight a courier charges for.
 * Items are stacked: the longest length, the widest width, heights added up. Couriers charge the
 * higher of the actual weight and the volumetric weight (L × W × H in cm ÷ 5000 kg).
 */
class Parcel
{
    public function __construct(
        public int $grams,
        public ?float $length = null,
        public ?float $width = null,
        public ?float $height = null,
        public string $packaging = 'flyer',
    ) {}

    /** @param  iterable<array{0: Product|null, 1: int}>  $items  [product, qty] pairs */
    public static function of(iterable $items): self
    {
        $grams = 0;
        $l = $w = $h = 0.0;
        $sized = false;
        $box = false;
        foreach ($items as [$product, $qty]) {
            $qty = max(1, (int) $qty);
            $grams += $qty * self::itemGrams($product);
            if ($product?->length_cm && $product->width_cm && $product->height_cm) {
                $sized = true;
                $l = max($l, self::cm($product->length_cm));
                $w = max($w, self::cm($product->width_cm));
                $h += $qty * self::cm($product->height_cm);
            }
            $box = $box || $product?->packaging === 'box';
        }

        return new self(max(50, $grams), $sized ? $l : null, $sized ? $w : null, $sized ? round($h, 1) : null, $box ? 'box' : 'flyer');
    }

    /**
     * One item's weight in grams. A "weight" over 30 can't be kilos for a gift parcel: it was
     * typed in grams (e.g. 350 for 350 g), so read it that way instead of quoting a 350 kg parcel.
     */
    public static function itemGrams(?Product $product): int
    {
        $kg = (float) ($product?->weight_kg ?? 0);
        if ($kg <= 0) {
            return (int) setting('delhivery.default_weight', 300);
        }

        return (int) round($kg > 30 ? $kg : $kg * 1000);
    }

    /** 350 → "350 g", 1500 → "1.5 kg". */
    public static function label(int $grams): string
    {
        return $grams < 1000 ? $grams.' g' : rtrim(rtrim(number_format($grams / 1000, 2), '0'), '.').' kg';
    }

    /** A side of 150 or more was typed in millimetres. */
    public static function cm(float|string $v): float
    {
        return (float) $v >= 150 ? (float) $v / 10 : (float) $v;
    }

    public function volumetricGrams(): int
    {
        return $this->length ? (int) ceil($this->length * $this->width * $this->height / 5) : 0;
    }

    /** What the courier bills: the higher of actual and volumetric weight. */
    public function chargeableGrams(): int
    {
        return max($this->grams, $this->volumetricGrams());
    }
}

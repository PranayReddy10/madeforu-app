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
        $default = (int) setting('delhivery.default_weight', 300);
        $grams = 0;
        $l = $w = $h = 0.0;
        $sized = false;
        $box = false;
        foreach ($items as [$product, $qty]) {
            $qty = max(1, (int) $qty);
            $grams += $qty * ($product?->weight_kg ? (int) round($product->weight_kg * 1000) : $default);
            if ($product?->length_cm && $product->width_cm && $product->height_cm) {
                $sized = true;
                $l = max($l, (float) $product->length_cm);
                $w = max($w, (float) $product->width_cm);
                $h += $qty * (float) $product->height_cm;
            }
            $box = $box || $product?->packaging === 'box';
        }

        return new self(max(50, $grams), $sized ? $l : null, $sized ? $w : null, $sized ? round($h, 1) : null, $box ? 'box' : 'flyer');
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

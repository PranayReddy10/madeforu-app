<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;

/**
 * Builds GA4-shaped e-commerce events. The layout pushes them into dataLayer, and the same events
 * are forwarded to gtag (GA4 / Google Ads) and Meta Pixel, so one list feeds every tool.
 */
class Analytics
{
    /** @var array<int, array> */
    public array $events = [];

    public function item(Product $p, ?float $price = null, int $qty = 1, ?string $variant = null): array
    {
        return array_filter([
            'item_id' => $p->sku ?: (string) $p->id,
            'item_name' => $p->name,
            'item_brand' => $p->brand,
            'item_category' => $p->primaryCategory?->name,
            'item_variant' => $variant,
            'price' => round($price ?? $p->currentPrice(), 2),
            'quantity' => $qty,
        ], fn ($v) => $v !== null);
    }

    public function push(string $event, array $ecommerce): static
    {
        $this->events[] = ['event' => $event, 'ecommerce' => $ecommerce];

        return $this;
    }

    public function viewItem(Product $p): static
    {
        return $this->push('view_item', ['currency' => 'INR', 'value' => $p->currentPrice(), 'items' => [$this->item($p)]]);
    }

    public function viewItemList(string $name, iterable $products): static
    {
        $items = [];
        foreach ($products as $i => $p) {
            $items[] = $this->item($p) + ['index' => $i, 'item_list_name' => $name];
        }

        return $this->push('view_item_list', ['item_list_name' => $name, 'items' => $items]);
    }

    public function purchase(Order $o): static
    {
        return $this->push('purchase', [
            'transaction_id' => $o->number,
            'value' => (float) $o->total,
            'shipping' => (float) $o->shipping,
            'currency' => 'INR',
            'coupon' => $o->coupon_code,
            'items' => $o->items->map(fn ($i) => array_filter([
                'item_id' => $i->sku ?: (string) $i->product_id,
                'item_name' => $i->name,
                'item_variant' => $i->options ? implode(' / ', $i->options) : null,
                'price' => (float) $i->unit_price,
                'quantity' => $i->qty,
            ]))->all(),
        ]);
    }
}

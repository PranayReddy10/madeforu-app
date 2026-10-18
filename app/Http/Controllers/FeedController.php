<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Product feeds for Google Merchant Center (free listings + Shopping ads) and the Meta/Instagram catalogue.
 * Variable products are sent one row per variant, grouped by item_group_id, as both platforms expect.
 */
class FeedController extends Controller
{
    private function rows()
    {
        $rows = [];
        $products = Product::active()->with(['images', 'variants', 'primaryCategory'])->get();
        foreach ($products as $p) {
            $base = [
                'title' => $p->name,
                'description' => str(strip_tags((string) ($p->description ?: $p->short_description ?: $p->name)))->squish()->limit(4900)->toString(),
                'link' => $p->url(),
                'image' => $p->mainImageUrl(),
                'additional_images' => $p->images->skip(1)->take(10)->map->url()->values()->all(),
                'brand' => $p->brand ?: config('store.name'),
                'product_type' => $p->primaryCategory ? collect($p->primaryCategory->ancestors())->push($p->primaryCategory)->pluck('name')->implode(' > ') : null,
                'google_category' => $p->google_category,
                'gtin' => $p->gtin,
                'shipping_weight' => $p->weight_kg,
            ];
            if ($p->type === 'variable' && $p->variants->isNotEmpty()) {
                foreach ($p->variants as $v) {
                    $rows[] = $base + [
                        'id' => $v->sku ?: 'MFU-'.$p->id.'-'.$v->id,
                        'group' => $p->sku ?: 'MFU-'.$p->id,
                        'title' => $p->name.' - '.implode(' / ', $v->options),
                        'price' => (float) $v->price,
                        'sale_price' => $v->currentPrice() < (float) $v->price ? $v->currentPrice() : null,
                        'availability' => $v->in_stock ? 'in_stock' : 'out_of_stock',
                        'image' => $v->image ? media_url($v->image) : $base['image'],
                        'options' => $v->options,
                        'link' => $p->url().'?variant='.$v->id,
                    ];
                }
            } else {
                $rows[] = $base + [
                    'id' => $p->sku ?: 'MFU-'.$p->id,
                    'group' => null,
                    'price' => (float) $p->price,
                    'sale_price' => $p->isOnSale() ? (float) $p->sale_price : null,
                    'availability' => $p->in_stock ? 'in_stock' : 'out_of_stock',
                    'options' => [],
                ];
            }
        }

        return $rows;
    }

    /** When Google Merchant Center / Meta last downloaded a feed (shown on the Analytics page). */
    public static function logFetch(string $feed): void
    {
        $ua = (string) request()->userAgent();
        Cache::forever('feed.fetched.'.$feed, ['at' => now()->toIso8601String(), 'by' => Str::limit($ua, 80), 'items' => null]);
    }

    public function google()
    {
        self::logFetch('google');

        return response()->view('feeds.google', ['rows' => $this->rows()])->header('Content-Type', 'application/xml');
    }

    public function facebook()
    {
        self::logFetch('facebook');
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['id', 'item_group_id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'additional_image_link', 'brand', 'product_type', 'google_product_category', 'size', 'color']);
        foreach ($this->rows() as $r) {
            $opt = collect($r['options'])->mapWithKeys(fn ($v, $k) => [strtolower($k) => $v]);
            fputcsv($out, [
                $r['id'], $r['group'], $r['title'], $r['description'], str_replace('_', ' ', $r['availability']), 'new',
                number_format($r['price'], 2, '.', '').' INR', $r['sale_price'] ? number_format($r['sale_price'], 2, '.', '').' INR' : '',
                $r['link'], $r['image'], implode(',', $r['additional_images']), $r['brand'], $r['product_type'], $r['google_category'],
                $opt->first(fn ($v, $k) => str_contains($k, 'size')), $opt->first(fn ($v, $k) => str_contains($k, 'colo')),
            ]);
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

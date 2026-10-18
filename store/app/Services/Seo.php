<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;

/**
 * Collects everything a page says to search engines and social apps: title, description,
 * canonical, Open Graph, and JSON-LD (Product, BreadcrumbList, FAQPage, Organization, WebSite).
 */
class Seo
{
    public string $title = '';

    public string $description = '';

    public ?string $canonical = null;

    public ?string $image = null;

    public string $type = 'website';

    public bool $noindex = false;

    public ?string $keywords = null;

    public ?string $prev = null;

    public ?string $next = null;

    /** @var array<int, array> */
    public array $schema = [];

    /** @var array<string, string> extra <meta property> tags (product:price:amount, etc.) */
    public array $og = [];

    public function title(string $t): static
    {
        $this->title = $t;

        return $this;
    }

    public function description(?string $d): static
    {
        $this->description = str((string) $d)->stripTags()->squish()->limit(160)->toString();

        return $this;
    }

    public function canonical(?string $url): static
    {
        $this->canonical = $url;

        return $this;
    }

    public function image(?string $url): static
    {
        $this->image = $url;

        return $this;
    }

    public function noindex(bool $v = true): static
    {
        $this->noindex = $v;

        return $this;
    }

    public function addSchema(array $schema): static
    {
        $this->schema[] = $schema;

        return $this;
    }

    public function fullTitle(): string
    {
        $name = config('store.name');
        if ($this->title === '') {
            return $name.' | '.config('store.tagline');
        }

        return str_contains($this->title, $name) ? $this->title : $this->title.' | '.$name;
    }

    /** @param array<int, array{0: string, 1: string}> $crumbs [name, url] */
    public function breadcrumbs(array $crumbs): static
    {
        return $this->addSchema([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($crumbs)->values()->map(fn ($c, $i) => [
                '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1],
            ])->all(),
        ]);
    }

    public function forProduct(Product $p): static
    {
        $images = $p->images->map->url()->values()->all() ?: [asset('images/placeholder.svg')];
        $this->title($p->seoTitle())
            ->description($p->seoDescription())
            ->canonical($p->canonical_url ?: $p->url())
            ->image($images[0])
            ->noindex($p->noindex);
        $this->type = 'product';
        $this->keywords = $p->meta_keywords ?: $p->focus_keyword;
        $this->og = [
            'product:price:amount' => number_format($p->currentPrice(), 2, '.', ''),
            'product:price:currency' => 'INR',
            'product:availability' => $p->in_stock ? 'in stock' : 'out of stock',
            'product:brand' => $p->brand,
            'product:condition' => 'new',
        ];

        $offer = fn (float $price, ?string $sku, bool $inStock, ?string $name = null) => array_filter([
            '@type' => 'Offer',
            'url' => $p->url(),
            'priceCurrency' => 'INR',
            'price' => number_format($price, 2, '.', ''),
            'priceValidUntil' => now()->addYear()->format('Y-12-31'),
            'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'sku' => $sku,
            'name' => $name,
            'seller' => ['@type' => 'Organization', 'name' => config('store.name')],
            'shippingDetails' => [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => $price >= (float) setting('free_shipping_over') ? 0 : (float) setting('shipping_fee'), 'currency' => 'INR'],
                'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'IN'],
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => max(1, $p->production_days), 'unitCode' => 'DAY'],
                    'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => 2, 'maxValue' => 7, 'unitCode' => 'DAY'],
                ],
            ],
            'hasMerchantReturnPolicy' => [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => 'IN',
                // Personalised goods are made to order; replacements only for damage in transit.
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
            ],
        ], fn ($v) => $v !== null);

        $product = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $p->url().'#product',
            'name' => $p->name,
            'description' => str(strip_tags((string) ($p->description ?: $p->short_description ?: $p->name)))->squish()->limit(5000)->toString(),
            'image' => $images,
            'sku' => $p->sku ?: 'MFU-'.$p->id,
            'mpn' => $p->sku ?: 'MFU-'.$p->id,
            'brand' => ['@type' => 'Brand', 'name' => $p->brand ?: config('store.name')],
            'category' => $p->primaryCategory?->name,
            'url' => $p->url(),
        ];
        if ($p->gtin) {
            $product['gtin'] = $p->gtin;
        }
        if ($p->type === 'variable' && $p->variants->isNotEmpty()) {
            $product['offers'] = [
                '@type' => 'AggregateOffer',
                'priceCurrency' => 'INR',
                'lowPrice' => number_format($p->currentPrice(), 2, '.', ''),
                'highPrice' => number_format($p->maxPrice(), 2, '.', ''),
                'offerCount' => $p->variants->count(),
                'availability' => $p->variants->contains('in_stock', true) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'offers' => $p->variants->map(fn ($v) => $offer($v->currentPrice(), $v->sku, $v->in_stock, $p->name.' - '.implode(' / ', $v->options)))->all(),
            ];
        } else {
            $product['offers'] = $offer($p->currentPrice(), $p->sku, $p->in_stock);
        }
        if ($p->rating_count > 0) {
            $product['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (float) $p->rating_avg, 'reviewCount' => $p->rating_count, 'bestRating' => 5, 'worstRating' => 1];
            $product['review'] = $p->reviews->take(5)->map(fn ($r) => [
                '@type' => 'Review',
                'author' => ['@type' => 'Person', 'name' => $r->name],
                'datePublished' => $r->created_at->toDateString(),
                'reviewBody' => (string) $r->body,
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $r->rating, 'bestRating' => 5],
            ])->values()->all();
        }
        $this->addSchema(array_filter($product, fn ($v) => $v !== null));

        $crumbs = [['Home', url('/')]];
        if ($cat = $p->primaryCategory) {
            foreach ($cat->ancestors() as $a) {
                $crumbs[] = [$a->name, $a->url()];
            }
            $crumbs[] = [$cat->name, $cat->url()];
        }
        $crumbs[] = [$p->name, $p->url()];
        $this->breadcrumbs($crumbs);

        if ($p->faqs) {
            $this->addSchema([
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => collect($p->faqs)->filter(fn ($f) => ! empty($f['q']) && ! empty($f['a']))->map(fn ($f) => [
                    '@type' => 'Question', 'name' => $f['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
                ])->values()->all(),
            ]);
        }

        return $this;
    }

    public function forCategory(Category $c, $products): static
    {
        $this->title($c->seoTitle())->description($c->seoDescription())->canonical($c->url())->image($c->imageUrl());
        $crumbs = [['Home', url('/')]];
        foreach ($c->ancestors() as $a) {
            $crumbs[] = [$a->name, $a->url()];
        }
        $crumbs[] = [$c->name, $c->url()];
        $this->breadcrumbs($crumbs);
        $this->addSchema([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $c->name,
            'url' => $c->url(),
            'description' => $this->description,
            'mainEntity' => [
                '@type' => 'ItemList',
                'itemListElement' => collect($products->items())->values()->map(fn ($p, $i) => [
                    '@type' => 'ListItem', 'position' => $i + 1, 'url' => $p->url(), 'name' => $p->name,
                ])->all(),
            ],
        ]);

        return $this;
    }

    /** Site-wide: who we are, and the sitelinks search box. */
    public function siteSchema(): array
    {
        $social = array_values(config('store.social'));

        return [
            array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'OnlineStore',
                '@id' => url('/').'#organization',
                'name' => config('store.name'),
                'url' => url('/'),
                'logo' => asset(config('store.logo')),
                'email' => config('store.email'),
                'telephone' => config('store.phone') ?: null,
                'address' => ['@type' => 'PostalAddress', 'addressLocality' => config('store.city'), 'addressRegion' => config('store.region'), 'postalCode' => config('store.postcode') ?: null, 'addressCountry' => 'IN'],
                'sameAs' => $social ?: null,
            ]),
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                '@id' => url('/').'#website',
                'name' => config('store.name'),
                'url' => url('/'),
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => url('/search').'?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];
    }
}

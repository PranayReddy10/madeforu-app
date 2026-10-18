<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Product extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'stock_qty' => 'integer',
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'in_stock' => 'boolean',
        'noindex' => 'boolean',
        'faqs' => 'array',
    ];

    protected static function booted(): void
    {
        // Publishing or hiding a product can add or empty a category in the menu.
        $forget = fn () => self::flushMenuCache();
        static::saved($forget);
        static::deleted($forget);
        // A counted stock decides "in stock" by itself.
        static::saving(function (Product $p) {
            if ($p->type !== 'variable' && $p->stock_qty !== null) {
                $p->in_stock = $p->stock_qty > 0;
            }
        });
    }

    public static function flushMenuCache(): void
    {
        Cache::forget('menu.categories');
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    public function customizationFields(): HasMany
    {
        return $this->hasMany(CustomizationField::class)->orderBy('position');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('is_approved', true)->latest();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeSearch(Builder $q, string $term): Builder
    {
        $words = array_filter(preg_split('/\s+/', trim($term)));

        return $q->where(function (Builder $q) use ($words) {
            foreach ($words as $w) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $w).'%';
                $q->where(fn (Builder $q) => $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('meta_keywords', 'like', $like)
                    ->orWhere('short_description', 'like', $like)
                    ->orWhereHas('categories', fn ($c) => $c->where('name', 'like', $like)));
            }
        });
    }

    public function url(): string
    {
        return url('product/'.$this->slug).'/';
    }

    public function isOnSale(): bool
    {
        return $this->sale_price !== null && (float) $this->sale_price > 0 && (float) $this->sale_price < (float) $this->price;
    }

    /** What the customer pays for the base product (variable products: the cheapest variant). */
    public function currentPrice(): float
    {
        if ($this->type === 'variable' && $this->relationLoaded('variants') && $this->variants->isNotEmpty()) {
            return (float) $this->variants->min(fn ($v) => $v->currentPrice());
        }

        return (float) ($this->isOnSale() ? $this->sale_price : $this->price);
    }

    public function maxPrice(): float
    {
        if ($this->type === 'variable' && $this->relationLoaded('variants') && $this->variants->isNotEmpty()) {
            return (float) $this->variants->max(fn ($v) => $v->currentPrice());
        }

        return $this->currentPrice();
    }

    public function regularPrice(): float
    {
        if ($this->type === 'variable' && $this->relationLoaded('variants') && $this->variants->isNotEmpty()) {
            $cheapest = $this->variants->sortBy(fn ($v) => $v->currentPrice())->first();

            return (float) $cheapest->price;
        }

        return (float) $this->price;
    }

    public function discountPercent(): int
    {
        $regular = $this->regularPrice();
        $now = $this->currentPrice();

        return $regular > 0 && $now < $regular ? (int) round(100 * ($regular - $now) / $regular) : 0;
    }

    public function mainImageUrl(): string
    {
        $img = $this->relationLoaded('images') ? $this->images->first() : $this->images()->first();

        return $img ? $img->url() : asset('images/placeholder.svg');
    }

    public function needsUpload(): bool
    {
        return $this->customizationFields->contains(fn ($f) => $f->type === 'images');
    }

    public function uploadCount(): int
    {
        return (int) $this->customizationFields->where('type', 'images')->sum('max_files');
    }

    public function seoTitle(): string
    {
        if ($this->meta_title) {
            return $this->meta_title;
        }
        $cat = $this->primaryCategory?->name;

        return str($this->name.($cat && ! str_contains(strtolower($this->name), strtolower($cat)) ? ' - '.$cat : '').' | Buy Online at ₹'.number_format($this->currentPrice()))->limit(68, '')->toString();
    }

    public function seoDescription(): string
    {
        if ($this->meta_description) {
            return $this->meta_description;
        }
        $base = strip_tags((string) ($this->short_description ?: $this->description));
        $base = $base ?: 'Personalised '.$this->name.' made with your photos.';

        return str($base)->squish()->limit(120)->append(' ₹'.number_format($this->currentPrice()).'. Free customisation, fast delivery across India.')->limit(160)->toString();
    }
}

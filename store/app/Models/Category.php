<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Category extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'show_on_home' => 'boolean'];

    protected static function booted(): void
    {
        // Keep `path` in step with the parent chain so /product-category/parent/child/ always resolves.
        static::saving(function (Category $c) {
            $parent = $c->parent_id ? Category::find($c->parent_id) : null;
            $c->path = $parent ? $parent->path.'/'.$c->slug : $c->slug;
        });
        static::saved(function (Category $c) {
            if ($c->wasChanged('path')) {
                $c->children->each->save();
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Ids of active categories that have at least one published product in them or below them.
     * Empty categories (only drafts, or nothing yet) stay reachable by URL but are left out of menus.
     *
     * @return array<int, int>
     */
    public static function withShopProductsIds(): array
    {
        $direct = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('products.is_active', true)
            ->distinct()->pluck('category_product.category_id')->all();
        $parents = static::active()->pluck('parent_id', 'id')->all();
        $keep = [];
        foreach ($direct as $id) {
            for ($c = $id; $c && array_key_exists($c, $parents) && ! isset($keep[$c]); $c = $parents[$c]) {
                $keep[$c] = true;
            }
        }

        return array_keys($keep);
    }

    /** Count only products customers can see. */
    public function scopeWithShopCount(Builder $q): Builder
    {
        return $q->withCount(['products' => fn ($p) => $p->where('is_active', true)]);
    }

    /** This category and every category beneath it. */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->descendantIds());
        }

        return $ids;
    }

    /** @return array<int, Category> root first */
    public function ancestors(): array
    {
        $chain = [];
        $c = $this->parent;
        while ($c) {
            array_unshift($chain, $c);
            $c = $c->parent;
        }

        return $chain;
    }

    public function url(): string
    {
        return url('product-category/'.$this->path).'/';
    }

    public function imageUrl(): ?string
    {
        return $this->image ? media_url($this->image) : null;
    }

    public function seoTitle(): string
    {
        return $this->meta_title ?: $this->name.' - Customised '.$this->name.' Online | '.config('store.name');
    }

    public function seoDescription(): string
    {
        return $this->meta_description
            ?: str($this->description ? strip_tags($this->description) : 'Shop personalised '.$this->name.' with your own photos and names. Made to order in India, delivered to your door.')->squish()->limit(158)->toString();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_approved' => 'boolean'];

    protected static function booted(): void
    {
        $refresh = function (Review $r) {
            $p = $r->product;
            if (! $p) {
                return;
            }
            $q = Review::where('product_id', $p->id)->where('is_approved', true);
            $p->forceFill(['rating_count' => $q->count(), 'rating_avg' => round((float) $q->avg('rating'), 2)])->saveQuietly();
        };
        static::saved($refresh);
        static::deleted($refresh);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Models;

use App\Services\ImageTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class Review extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_approved' => 'boolean', 'is_verified' => 'boolean', 'photos' => 'array'];

    /** @return array<int, string> */
    public function photoUrls(): array
    {
        return array_map(fn ($p) => media_url($p), $this->photos ?? []);
    }

    /**
     * Save review photos: resized to 1600px JPEG (which also drops the phone's GPS data) on the media disk.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string>
     */
    public static function storePhotos(array $files): array
    {
        $paths = [];
        foreach (array_slice($files, 0, 3) as $file) {
            $tmp = tempnam(sys_get_temp_dir(), 'rv').'.jpg';
            if (! ImageTools::thumbnail($file->getRealPath(), $tmp, 1600)) {
                continue;   // not an image GD can read
            }
            $path = 'reviews/'.now()->format('Y/m').'/'.Str::random(20).'.jpg';
            media_disk()->put($path, (string) file_get_contents($tmp));
            @unlink($tmp);
            $paths[] = $path;
        }

        return $paths;
    }

    /** Did this person buy this product (a delivered order)? */
    public static function boughtBy(int $productId, ?User $user, ?string $email = null): ?Order
    {
        if (! $user && ! $email) {
            return null;
        }

        return Order::whereIn('status', ['delivered', 'shipped'])
            ->where(fn ($q) => $q->when($user, fn ($q) => $q->where('user_id', $user->id)->orWhere('email', $user->email))->when($email, fn ($q) => $q->orWhere('email', $email)))
            ->whereHas('items', fn ($q) => $q->where('product_id', $productId))->latest()->first();
    }

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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

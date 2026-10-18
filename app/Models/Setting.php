<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    public static function all_(): array
    {
        return Cache::rememberForever('settings', function () {
            try {
                return Schema::hasTable('settings') ? static::pluck('value', 'key')->all() : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** Keys stored encrypted (payment secrets). */
    public const SECRET = ['razorpay.secret', 'razorpay.webhook_secret', 'cashfree.secret', 'delhivery.token'];

    public static function put(array $values): void
    {
        foreach ($values as $k => $v) {
            if (in_array($k, self::SECRET, true) && $v !== null && $v !== '') {
                $v = 'enc:'.Crypt::encryptString((string) $v);
            }
            static::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Cache::forget('settings');
    }

    public static function decode(?string $value): ?string
    {
        if ($value !== null && str_starts_with($value, 'enc:')) {
            try {
                return Crypt::decryptString(substr($value, 4));
            } catch (\Throwable) {
                return null; // APP_KEY changed: treat as not set
            }
        }

        return $value;
    }
}

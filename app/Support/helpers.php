<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

if (! function_exists('media_url')) {
    /** A product/category image path is either an absolute URL (not yet downloaded) or a path on the public disk. */
    function media_url(?string $path): string
    {
        if (! $path) {
            return asset('images/placeholder.svg');
        }
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}

if (! function_exists('money')) {
    /** ₹1,23,456 — Indian digit grouping, paise only when there are some. */
    function money(float|string|null $amount): string
    {
        $amount = (float) $amount;
        $neg = $amount < 0;
        $amount = abs($amount);
        $whole = (string) floor($amount);
        $paise = (int) round(($amount - floor($amount)) * 100);
        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest.','.$last3;
        }

        return ($neg ? '-' : '').'₹'.$whole.($paise ? '.'.str_pad((string) $paise, 2, '0', STR_PAD_LEFT) : '');
    }
}

if (! function_exists('setting')) {
    /** Admin-editable setting, falling back to config/store.php. */
    function setting(string $key, mixed $default = null): mixed
    {
        $all = Setting::all_();
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }

        return config('store.'.$key, $default);
    }
}

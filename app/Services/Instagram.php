<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Latest Instagram posts for the home page, from the Instagram API (Instagram Login, professional account).
 * The access token lasts 60 days; store:instagram-refresh renews it weekly.
 */
class Instagram
{
    public static function enabled(): bool
    {
        return (bool) setting('instagram.token');
    }

    public static function handle(): ?string
    {
        $url = (string) config('store.social.instagram');

        return $url !== '' ? '@'.trim((string) parse_url($url, PHP_URL_PATH), '/') : null;
    }

    /** @return array<int, array{image: string, link: string, caption: string, video: bool}> */
    public static function posts(int $limit = 8): array
    {
        if (! self::enabled()) {
            return [];
        }

        return Cache::remember('instagram.posts', 3 * 3600, function () use ($limit) {
            try {
                $res = Http::timeout(12)->get('https://graph.instagram.com/me/media', [
                    'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink',
                    'limit' => $limit,
                    'access_token' => setting('instagram.token'),
                ]);
            } catch (\Throwable) {
                return [];
            }

            return collect($res->json('data') ?? [])->map(fn ($m) => [
                'image' => ($m['media_type'] ?? '') === 'VIDEO' ? ($m['thumbnail_url'] ?? '') : ($m['media_url'] ?? ''),
                'link' => $m['permalink'] ?? '',
                'caption' => mb_substr(trim((string) ($m['caption'] ?? '')), 0, 120),
                'video' => ($m['media_type'] ?? '') === 'VIDEO',
            ])->filter(fn ($p) => $p['image'] && $p['link'])->values()->take($limit)->all();
        });
    }

    /** Swap the token for a fresh 60-day one. */
    public static function refresh(): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $res = Http::timeout(15)->get('https://graph.instagram.com/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => setting('instagram.token')]);
        if ($res->successful() && ($t = $res->json('access_token'))) {
            Setting::put(['instagram.token' => $t]);

            return true;
        }

        return false;
    }
}

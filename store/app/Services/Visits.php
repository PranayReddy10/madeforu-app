<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * First-party visitor analytics: page views and shopping steps, stored in analytics_events.
 * A visitor is a random id in a first-party cookie (no IP addresses, nothing shared with anyone).
 * Where they came from is decided on the first page of each visit and kept in the session.
 */
class Visits
{
    public const COOKIE = 'mfu_v';

    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview|lighthouse|headless|curl|wget|python|httpclient|monitor|uptime|semrush|ahrefs|mj12|dataforseo|petal|bytespider|gptbot/i';

    public static function isBot(Request $request): bool
    {
        $ua = (string) $request->userAgent();

        return $ua === '' || preg_match(self::BOTS, $ua) === 1 || $request->header('Purpose') === 'prefetch' || $request->header('Sec-Purpose') === 'prefetch';
    }

    public static function visitor(Request $request): string
    {
        $id = (string) $request->cookie(self::COOKIE);
        if (! preg_match('/^[a-f0-9]{16}$/', $id)) {
            $id = substr(bin2hex(random_bytes(8)), 0, 16);
            Cookie::queue(self::COOKIE, $id, 60 * 24 * 365, null, null, null, true, false, 'Lax');
            $request->cookies->set(self::COOKIE, $id);
        }

        return $id;
    }

    /** Google, Instagram, WhatsApp… from utm_source, ad click ids or the referring site. */
    public static function source(Request $request): string
    {
        if ($utm = $request->query('utm_source')) {
            return Str::limit(ucfirst(strtolower(preg_replace('/[^\w .\-]/', '', (string) $utm))), 40, '');
        }
        if ($request->query('gclid')) {
            return 'Google Ads';
        }
        if ($request->query('fbclid')) {
            return 'Facebook / Instagram';
        }
        $host = strtolower((string) parse_url((string) $request->headers->get('referer'), PHP_URL_HOST));
        if ($host === '' || $host === $request->getHost()) {
            return 'Direct';
        }

        return match (true) {
            str_contains($host, 'google.') => 'Google',
            str_contains($host, 'instagram.') => 'Instagram',
            str_contains($host, 'facebook.') || $host === 'l.facebook.com' || $host === 'm.facebook.com' => 'Facebook',
            str_contains($host, 'whatsapp') || $host === 'wa.me' => 'WhatsApp',
            str_contains($host, 'bing.') => 'Bing',
            str_contains($host, 'youtube.') => 'YouTube',
            str_contains($host, 'pinterest.') => 'Pinterest',
            str_contains($host, 'yahoo.') || str_contains($host, 'duckduckgo.') => 'Other search',
            default => Str::limit(preg_replace('/^www\./', '', $host), 40, ''),
        };
    }

    public static function device(Request $request): string
    {
        $ua = (string) $request->userAgent();

        return match (true) {
            (bool) preg_match('/ipad|tablet/i', $ua) => 'tablet',
            (bool) preg_match('/mobi|android|iphone/i', $ua) => 'mobile',
            default => 'desktop',
        };
    }

    /** Record a page view or shopping step for the current visitor. Never breaks the page. */
    public static function record(Request $request, string $type, array $extra = []): void
    {
        if (self::isBot($request) || $request->user()?->is_admin) {
            return;
        }
        try {
            // The source of a visit sticks for 30 minutes of activity, like Google Analytics sessions.
            $src = session('mfu_src');
            if (! $src || now()->timestamp - (int) session('mfu_src_at') > 1800 || $request->query('utm_source') || $request->query('gclid')) {
                $fresh = self::source($request);
                $src = $fresh !== 'Direct' || ! $src ? $fresh : $src;
            }
            session(['mfu_src' => $src, 'mfu_src_at' => now()->timestamp]);

            DB::table('analytics_events')->insert($extra + [
                'day' => today()->toDateString(),
                'visitor' => self::visitor($request),
                'type' => $type,
                'path' => Str::limit('/'.ltrim($request->path(), '/'), 190, ''),
                'source' => $src,
                'device' => self::device($request),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

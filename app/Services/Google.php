<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Google Analytics 4 (Data API) and Search Console, read with a service account.
 * Setup (once): Google Cloud → create a service account → JSON key; enable "Google Analytics Data API"
 * and "Google Search Console API"; add the service account's email as a Viewer in GA4 and as a user
 * (Full, to submit sitemaps) in Search Console. Paste the JSON key in Admin → Settings.
 */
class Google
{
    public static function credentials(): ?array
    {
        $json = json_decode((string) setting('google.service_account'), true);

        return is_array($json) && ! empty($json['client_email']) && ! empty($json['private_key']) ? $json : null;
    }

    public static function analyticsReady(): bool
    {
        return self::credentials() && setting('google.ga4_property');
    }

    public static function searchReady(): bool
    {
        return self::credentials() && self::site();
    }

    /** Search Console property: "sc-domain:madeforu.co.in" or "https://madeforu.co.in/". */
    public static function site(): ?string
    {
        $s = trim((string) setting('google.search_console_site'));

        return $s !== '' ? $s : null;
    }

    private function token(): string
    {
        $cred = self::credentials() ?? throw new RuntimeException('Add the Google service account key in Settings.');

        return Cache::remember('google.token.'.md5($cred['client_email']), 3300, function () use ($cred) {
            $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
            $now = time();
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = $b64(json_encode([
                'iss' => $cred['client_email'],
                'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            if (! openssl_sign($head.'.'.$claim, $sig, $cred['private_key'], 'sha256WithRSAEncryption')) {
                throw new RuntimeException('The Google service account key could not be read. Paste the whole JSON file again.');
            }
            $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $head.'.'.$claim.'.'.$b64($sig),
            ]);
            if (! $res->successful()) {
                throw new RuntimeException('Google sign-in failed: '.($res->json('error_description') ?: Str::limit($res->body(), 200)));
            }

            return (string) $res->json('access_token');
        });
    }

    private function call(string $method, string $url, array $body = []): array
    {
        $res = Http::withToken($this->token())->acceptJson()->timeout(30)->{$method}($url, $body);
        if (! $res->successful()) {
            throw new RuntimeException(Str::limit((string) ($res->json('error.message') ?: $res->body()), 300));
        }

        return $res->json() ?? [];
    }

    /** GA4 report rows: [['dims' => [...], 'mets' => [...]], ...] */
    public function analytics(array $dimensions, array $metrics, int $days = 28, int $limit = 10, ?string $orderMetric = null): array
    {
        $prop = preg_replace('/\D/', '', (string) setting('google.ga4_property'));
        $body = [
            'dateRanges' => [['startDate' => $days.'daysAgo', 'endDate' => 'today']],
            'dimensions' => array_map(fn ($d) => ['name' => $d], $dimensions),
            'metrics' => array_map(fn ($m) => ['name' => $m], $metrics),
            'limit' => $limit,
        ];
        if ($orderMetric) {
            $body['orderBys'] = [['metric' => ['metricName' => $orderMetric], 'desc' => true]];
        } elseif ($dimensions === ['date']) {
            $body['orderBys'] = [['dimension' => ['dimensionName' => 'date']]];
        }
        $data = $this->call('post', "https://analyticsdata.googleapis.com/v1beta/properties/{$prop}:runReport", $body);

        return collect($data['rows'] ?? [])->map(fn ($r) => [
            'dims' => array_column($r['dimensionValues'] ?? [], 'value'),
            'mets' => array_map('floatval', array_column($r['metricValues'] ?? [], 'value')),
        ])->all();
    }

    /** Search Console performance: clicks, impressions, CTR and position, grouped by query, page or date. */
    public function search(array $dimensions, int $days = 28, int $limit = 10): array
    {
        $site = rawurlencode((string) self::site());
        $data = $this->call('post', "https://www.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query", [
            'startDate' => now()->subDays($days + 2)->toDateString(),   // Search Console data lags ~2 days
            'endDate' => now()->subDays(2)->toDateString(),
            'dimensions' => $dimensions,
            'rowLimit' => $limit,
        ]);

        return $data['rows'] ?? [];
    }

    public function sitemaps(): array
    {
        return $this->call('get', 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode((string) self::site()).'/sitemaps')['sitemap'] ?? [];
    }

    public function submitSitemap(string $url): void
    {
        $this->call('put', 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode((string) self::site()).'/sitemaps/'.rawurlencode($url));
    }

    /** URL Inspection: is this page in Google's index? (Google allows 2,000 a day per property.) */
    public function inspect(string $url): array
    {
        $r = $this->call('post', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'inspectionUrl' => $url, 'siteUrl' => self::site(),
        ]);

        return $r['inspectionResult']['indexStatusResult'] ?? [];
    }
}

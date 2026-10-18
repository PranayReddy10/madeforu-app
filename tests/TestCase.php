<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** The store keeps WordPress's trailing slashes (/product/slug/), so the test client must not strip them. */
    protected function prepareUrlForRequest($uri)
    {
        $slash = is_string($uri) && strlen($uri) > 1 && str_ends_with(parse_url($uri, PHP_URL_PATH) ?? '', '/');
        $url = parent::prepareUrlForRequest($uri);
        if ($slash && ! str_contains($url, '?')) {
            $url .= '/';
        }

        return $url;
    }

    /** Behave like a browser: keep one session cookie across requests (uploads and the cart belong to it). */
    protected function useBrowserSession(): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('t', 40));
    }
}

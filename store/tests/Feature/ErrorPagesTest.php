<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_urls_show_the_404_page_not_a_server_error(): void
    {
        foreach (['/xmlrpc.php', '/wp-login.php', '/favicon.ico', '/no-such-page/', '/a/b/c.html'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Instagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshInstagram extends Command
{
    protected $signature = 'store:instagram-refresh';

    protected $description = 'Renew the Instagram access token (it expires after 60 days) and reload the posts';

    public function handle(): int
    {
        if (! Instagram::enabled()) {
            return self::SUCCESS;
        }
        $this->line(Instagram::refresh() ? 'Instagram token renewed.' : 'Instagram token could not be renewed — paste a new one in Settings.');
        Cache::forget('instagram.posts');

        return self::SUCCESS;
    }
}

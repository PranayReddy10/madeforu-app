<?php

namespace App\Console\Commands;

use App\Services\ImportQueue;
use Illuminate\Console\Command;

class RunQueuedImport extends Command
{
    protected $signature = 'store:import-queued';

    protected $description = 'Run a WooCommerce import started from Admin → Import (the scheduler checks every minute)';

    public function handle(): int
    {
        if (ImportQueue::runQueued()) {
            $this->line(ImportQueue::logTail(5));
        }

        return self::SUCCESS;
    }
}

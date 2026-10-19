<?php

namespace App\Console\Commands;

use App\Services\Sku;
use Illuminate\Console\Command;

class FillSkus extends Command
{
    protected $signature = 'store:skus';

    protected $description = 'Give every product and variation without a SKU one (MFU-MAG-007, MFU-MAG-007-RND); existing SKUs are kept';

    public function handle(): int
    {
        $this->info('Created '.Sku::fillMissing().' SKUs.');

        return self::SUCCESS;
    }
}

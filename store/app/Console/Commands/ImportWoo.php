<?php

namespace App\Console\Commands;

use App\Services\WooImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ImportWoo extends Command
{
    protected $signature = 'store:import-woo
        {url? : WooCommerce site, default WOO_URL from .env}
        {--csv= : Import a WooCommerce product export CSV instead of calling the site}
        {--no-images : Keep image URLs pointing at the old site instead of downloading them}
        {--no-upload-fields : Do not add the default "upload your photo" field to new products}
        {--skip-pages : Do not import WordPress pages}
        {--skip-reviews : Do not import product reviews}';

    protected $description = 'Copy categories, products, prices, variations, images, reviews and pages from the WooCommerce site';

    public function handle(): int
    {
        $importer = new WooImporter($this->argument('url'));
        $importer->downloadImages = ! $this->option('no-images');
        $importer->addUploadFields = ! $this->option('no-upload-fields');
        $importer->log = fn ($m) => $this->line($m);

        try {
            if ($csv = $this->option('csv')) {
                $importer->importCsv($csv);
            } else {
                $importer->run(! $this->option('skip-pages'), ! $this->option('skip-reviews'));
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('If the site blocks the API (Cloudflare, security plugin), export products from WooCommerce → Products → Export and run with --csv=file.csv');

            return self::FAILURE;
        }
        Cache::forget('menu.categories');
        $this->table(['What', 'Count'], collect($importer->stats)->map(fn ($v, $k) => [$k, $v])->values());
        $this->info('Done. Open /admin/products to set how many photos each product takes.');

        return self::SUCCESS;
    }
}

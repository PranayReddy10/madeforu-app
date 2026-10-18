<?php

namespace App\Console\Commands;

use App\Services\WooImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ImportWoo extends Command
{
    protected $signature = 'store:import-woo
        {url? : WooCommerce site, default WOO_URL from .env}
        {--key= : WooCommerce REST API consumer key (ck_...). With key + secret, drafts are imported too (hidden)}
        {--secret= : WooCommerce REST API consumer secret (cs_...)}
        {--csv= : Import a WooCommerce product export CSV instead of calling the site}
        {--customers : Also import customer accounts and past orders (needs --key/--secret)}
        {--only-customers : Import only customer accounts and past orders}
        {--no-images : Keep image URLs pointing at the old site instead of downloading them}
        {--no-upload-fields : Do not add the default "upload your photo" field to new products}
        {--skip-pages : Do not import WordPress pages}
        {--skip-reviews : Do not import product reviews}';

    protected $description = 'Copy categories, products, prices, variations, images, reviews and pages from the WooCommerce site';

    public function handle(): int
    {
        $importer = new WooImporter($this->argument('url'), $this->option('key'), $this->option('secret'));
        $this->line($importer->usesApiKeys()
            ? 'Using WooCommerce REST API keys: published, draft and private products.'
            : 'No API keys: public Store API, published products only. Pass --key/--secret (or WOO_CONSUMER_KEY/SECRET) to include drafts.');
        $importer->downloadImages = ! $this->option('no-images');
        $importer->addUploadFields = ! $this->option('no-upload-fields');
        $importer->log = fn ($m) => $this->line($m);

        try {
            if ($csv = $this->option('csv')) {
                $importer->importCsv($csv);
            } elseif (! $this->option('only-customers')) {
                $importer->run(! $this->option('skip-pages'), ! $this->option('skip-reviews'));
            }
            if ($this->option('customers') || $this->option('only-customers')) {
                $importer->importCustomers();
                $importer->importOrders();
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('If the site blocks the API (Cloudflare, security plugin), export products from WooCommerce → Products → Export and run with --csv=file.csv');

            return self::FAILURE;
        }
        Cache::forget('menu.categories');
        Cache::forget('menu.pages');
        $this->table(['What', 'Count'], collect($importer->stats)->map(fn ($v, $k) => [$k, $v])->values());
        $this->info('Done. Open /admin/products to set how many photos each product takes.');

        return self::SUCCESS;
    }
}

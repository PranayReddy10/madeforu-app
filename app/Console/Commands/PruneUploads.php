<?php

namespace App\Console\Commands;

use App\Models\CustomerUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneUploads extends Command
{
    protected $signature = 'store:prune-uploads {--days=7}';

    protected $description = 'Delete customer photos that never became part of an order';

    public function handle(): int
    {
        $n = 0;
        CustomerUpload::whereNull('order_item_id')->where('created_at', '<', now()->subDays((int) $this->option('days')))
            ->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $u) {
                    Storage::disk('local')->delete(array_filter([$u->path, $u->thumb_path]));
                    $u->delete();
                    $n++;
                }
            });
        $this->info("Removed {$n} abandoned uploads.");

        return self::SUCCESS;
    }
}

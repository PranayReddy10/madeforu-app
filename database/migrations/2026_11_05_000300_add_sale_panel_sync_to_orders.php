<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            // Copy of the delivered order in the sale panel (sale.madeforu.co.in).
            $t->string('sale_order_no', 30)->nullable();
            $t->timestamp('sale_synced_at')->nullable();
            $t->string('sale_sync_error', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['sale_order_no', 'sale_synced_at', 'sale_sync_error']));
    }
};

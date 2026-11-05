<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            // When Delhivery paid the collected cash into the shop's bank (Admin → Sales).
            $t->timestamp('cod_remitted_at')->nullable();
            $t->string('cod_remittance_ref', 100)->nullable();
        });
        // Orders from the old WordPress shop were settled there.
        DB::table('orders')->whereNotNull('woo_id')->where('payment_method', 'cod')->where('status', 'delivered')
            ->update(['cod_remitted_at' => DB::raw('COALESCE(delivered_at, updated_at)'), 'cod_remittance_ref' => 'Old WordPress shop']);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['cod_remitted_at', 'cod_remittance_ref']));
    }
};

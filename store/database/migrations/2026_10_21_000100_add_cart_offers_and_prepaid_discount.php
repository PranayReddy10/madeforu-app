<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $t) {
            $t->boolean('show_on_cart')->default(false);
        });
        Schema::table('orders', function (Blueprint $t) {
            // Part of `discount`, kept apart so the order can say "online payment discount".
            $t->decimal('prepaid_discount', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('coupons', fn (Blueprint $t) => $t->dropColumn('show_on_cart'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('prepaid_discount'));
    }
};

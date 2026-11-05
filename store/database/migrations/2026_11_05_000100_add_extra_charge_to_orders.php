<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            // Added by the shop after ordering (rush making, gift wrap, a changed address…); part of `total`.
            $t->decimal('extra_charge', 10, 2)->default(0);
            $t->string('extra_charge_label', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['extra_charge', 'extra_charge_label']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Cashfree needs a fresh order id per payment attempt, so it is not always the order number.
            $table->string('cashfree_order_id')->nullable()->index()->after('razorpay_payment_id');
            $table->string('cashfree_payment_id')->nullable()->after('cashfree_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['cashfree_order_id', 'cashfree_payment_id']));
    }
};

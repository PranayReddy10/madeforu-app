<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What one costs to make (materials + printing), for the profit view
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('cost_price', 10, 2)->nullable()->after('sale_price');
            // Live preview: where the customer's photo sits on the product picture
            $t->json('preview')->nullable();
        });
        Schema::table('product_variants', fn (Blueprint $t) => $t->decimal('cost_price', 10, 2)->nullable()->after('sale_price'));
        // The cost at the time of the order, so later cost changes don't rewrite past profit
        Schema::table('order_items', fn (Blueprint $t) => $t->decimal('unit_cost', 10, 2)->nullable()->after('unit_price'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['cost_price', 'preview']));
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('cost_price'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
    }
};

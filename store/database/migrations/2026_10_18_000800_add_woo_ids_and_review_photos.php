<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('woo_id')->nullable()->unique()->after('id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('woo_id')->nullable()->unique()->after('id');   // imported from WooCommerce
            $table->timestamp('review_requested_at')->nullable()->after('delivered_at');
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('body');
            $table->boolean('is_verified')->default(false)->after('photos');
            $table->foreignId('order_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $t) {
            $t->dropConstrainedForeignId('order_id');
            $t->dropColumn(['photos', 'is_verified']);
        });
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['woo_id', 'review_requested_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('woo_id'));
    }
};

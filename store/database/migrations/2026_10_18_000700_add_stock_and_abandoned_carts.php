<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // products.stock_qty already exists: null = made to order, not counted.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('stock_qty')->nullable()->after('in_stock');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('stock_reduced')->default(false)->after('status');
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('change');
            $table->integer('balance');
            $table->string('reason', 40);       // order, cancelled, returned, adjustment
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'created_at']);
        });
        Schema::create('abandoned_carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('session_id', 100)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 20)->nullable()->index();
            $table->json('lines');
            $table->decimal('value', 10, 2)->default(0);
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->foreignId('recovered_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_carts');
        Schema::dropIfExists('stock_movements');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('stock_reduced'));
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('stock_qty'));
    }
};

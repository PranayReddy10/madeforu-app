<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->unique()->after('number');   // MFU/26-27/0001
            $table->timestamp('invoiced_at')->nullable()->after('invoice_number');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('hsn_code', 12)->nullable()->after('gtin');
            $table->decimal('gst_rate', 5, 2)->nullable()->after('hsn_code');     // blank = the store's default rate
        });
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 20);          // whatsapp
            $table->string('event', 40);            // confirmed, shipped, delivered, admin_alert
            $table->string('to', 20);
            $table->string('status', 20);           // sent, failed
            $table->string('provider_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['hsn_code', 'gst_rate']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['invoice_number', 'invoiced_at']));
    }
};

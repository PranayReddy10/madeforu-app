<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // tracking_number holds the Delhivery AWB, courier says "Delhivery".
            $table->string('shipment_status')->nullable()->after('courier');   // Delhivery's own words: Manifested, In Transit, Delivered…
            $table->text('shipment_error')->nullable()->after('shipment_status');
            $table->json('shipment_scans')->nullable()->after('shipment_error');
            $table->unsignedInteger('weight_grams')->nullable()->after('shipment_scans');
            $table->timestamp('shipped_at')->nullable()->after('weight_grams');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
            $table->timestamp('tracked_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn([
            'shipment_status', 'shipment_error', 'shipment_scans', 'weight_grams', 'shipped_at', 'delivered_at', 'tracked_at',
        ]));
    }
};

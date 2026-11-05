<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Packed size and packaging, for real courier charges and Delhivery bookings. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('length_cm', 6, 1)->nullable()->after('weight_kg');
            $t->decimal('width_cm', 6, 1)->nullable()->after('length_cm');
            $t->decimal('height_cm', 6, 1)->nullable()->after('width_cm');
            $t->string('packaging', 10)->nullable()->after('height_cm');   // flyer | box
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['length_cm', 'width_cm', 'height_cm', 'packaging']));
    }
};

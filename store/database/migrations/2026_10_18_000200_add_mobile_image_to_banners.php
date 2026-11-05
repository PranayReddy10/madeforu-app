<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            // Phones get a taller crop (about 1080×1200) instead of a squashed wide banner.
            $table->string('mobile_image')->nullable()->after('image');
            // Off when the image already has its text designed into it.
            $table->boolean('show_text')->default(true)->after('button');
        });
    }

    public function down(): void
    {
        Schema::table('banners', fn (Blueprint $t) => $t->dropColumn(['mobile_image', 'show_text']));
    }
};

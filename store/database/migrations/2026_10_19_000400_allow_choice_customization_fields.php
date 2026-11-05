<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The field type was a fixed list; "choice" (picture tiles: shapes, styles…) is new. */
    public function up(): void
    {
        Schema::table('customization_fields', function (Blueprint $t) {
            $t->string('type', 20)->default('text')->change();
        });
    }

    public function down(): void
    {
        // Left as text: turning it back into a list would fail on rows using "choice".
    }
};

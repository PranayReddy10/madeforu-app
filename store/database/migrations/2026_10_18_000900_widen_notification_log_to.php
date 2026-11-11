<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Email addresses need more room than phone numbers.
        Schema::table('notification_logs', fn (Blueprint $t) => $t->string('to', 191)->change());
    }

    public function down(): void
    {
        Schema::table('notification_logs', fn (Blueprint $t) => $t->string('to', 20)->change());
    }
};

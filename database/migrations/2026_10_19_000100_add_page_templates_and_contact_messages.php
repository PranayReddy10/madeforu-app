<?php

use App\Support\DefaultPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $t) {
            $t->string('template', 30)->nullable()->after('slug');
            $t->text('intro')->nullable()->after('title');
        });
        Schema::create('contact_messages', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('topic', 60)->nullable();
            $t->string('order_number', 40)->nullable();
            $t->text('message');
            $t->string('ip', 45)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        // Give About, Contact and the policies their designed layout, replacing page-builder leftovers.
        DefaultPages::apply();
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::table('pages', fn (Blueprint $t) => $t->dropColumn(['template', 'intro']));
    }
};

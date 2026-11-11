<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The store's own visitor analytics: one row per page view or shopping step. No IP addresses kept.
        Schema::create('analytics_events', function (Blueprint $t) {
            $t->id();
            $t->date('day')->index();
            $t->char('visitor', 16)->index();
            $t->string('type', 12);                       // view, product, cart, checkout, order
            $t->string('path', 191)->nullable();
            $t->foreignId('product_id')->nullable()->index();
            $t->foreignId('order_id')->nullable();
            $t->decimal('value', 10, 2)->nullable();
            $t->string('source', 40)->default('Direct');
            $t->string('device', 10)->default('mobile');
            $t->timestamp('created_at')->nullable()->index();
            $t->index(['day', 'type']);
        });
        // Google Search Console URL Inspection results: is each page in Google's index?
        Schema::create('search_index_status', function (Blueprint $t) {
            $t->id();
            $t->string('url', 191)->unique();
            $t->string('kind', 12)->default('product');   // product, category, page, home
            $t->string('verdict', 20)->nullable();        // PASS, NEUTRAL, FAIL
            $t->string('coverage', 120)->nullable();      // "Submitted and indexed", "Crawled - currently not indexed", ...
            $t->timestamp('last_crawl_at')->nullable();
            $t->timestamp('checked_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index_status');
        Schema::dropIfExists('analytics_events');
    }
};

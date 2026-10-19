<?php

use App\Http\Controllers\Api\CatalogController;
use Illuminate\Support\Facades\Route;

/* Read-only product catalogue (JSON) — for apps, marketplaces and other sites. See GET /api/v1. */
Route::prefix('v1')->middleware(['throttle:120,1', \App\Http\Middleware\ApiKey::class])->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('api.index');
    Route::get('products', [CatalogController::class, 'products'])->name('api.products');
    Route::get('products/{product}', [CatalogController::class, 'product'])->name('api.product');
    Route::get('categories', [CatalogController::class, 'categories'])->name('api.categories');
});

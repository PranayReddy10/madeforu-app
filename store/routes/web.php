<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReviewRequestController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\UploadController;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| URLs mirror WooCommerce exactly so every link Google already knows keeps working:
|   /product/{slug}/                     single product
|   /product-category/{parent}/{child}/  category (nested paths supported)
|   /shop/                               all products
|   /cart/  /checkout/  /my-account/     same as WooCommerce's default pages
*/

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/shop', [CategoryController::class, 'shop'])->name('shop');
Route::get('/shop/page/{page}', [CategoryController::class, 'shopPaged'])->whereNumber('page');
Route::get('/categories', [CategoryController::class, 'all'])->name('categories');
Route::get('/product-category/{path}/page/{page}', [CategoryController::class, 'showPaged'])->where('path', '.+')->whereNumber('page');
Route::get('/product-category/{path}', [CategoryController::class, 'show'])->where('path', '.+')->name('category');
Route::get('/product/{slug}', [ProductController::class, 'show'])->name('product');
Route::post('/product/{product}/review', [ProductController::class, 'review'])->name('product.review')->middleware('throttle:5,10');
Route::get('/product-tag/{tag}', [SearchController::class, 'tag']);

Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/delivery-check', [ProductController::class, 'deliveryCheck'])->name('delivery.check')->middleware('throttle:30,1');
Route::get('/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest')->middleware('throttle:60,1');

Route::post('/uploads', [UploadController::class, 'store'])->name('upload.store')->middleware('throttle:60,1');
Route::delete('/uploads/{token}', [UploadController::class, 'destroy'])->name('upload.destroy');
Route::get('/uploads/{token}', [UploadController::class, 'show'])->name('upload.show');

Route::get('/cart', [CartController::class, 'index'])->name('cart');
Route::post('/cart/add/{product}', [CartController::class, 'add'])->name('cart.add');
Route::post('/cart/update', [CartController::class, 'update'])->name('cart.update');
Route::post('/cart/remove/{line}', [CartController::class, 'remove'])->name('cart.remove');
Route::post('/cart/coupon', [CartController::class, 'coupon'])->name('cart.coupon')->middleware('throttle:10,1');
Route::post('/cart/coupon/remove', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');
Route::post('/checkout/capture', [CartController::class, 'capture'])->name('checkout.capture')->middleware('throttle:30,1');
Route::get('/cart/recover/{token}', [CartController::class, 'recover'])->name('cart.recover');
Route::get('/cart/unsubscribe/{token}', [CartController::class, 'unsubscribe'])->name('cart.unsubscribe');

Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout');
Route::post('/checkout', [CheckoutController::class, 'place'])->name('checkout.place')->middleware('throttle:10,1');
Route::post('/checkout/razorpay/verify', [CheckoutController::class, 'verify'])->name('checkout.verify');
Route::post('/webhooks/razorpay', [CheckoutController::class, 'webhook'])->name('webhooks.razorpay');
Route::get('/checkout/cashfree/return', [CheckoutController::class, 'cashfreeReturn'])->name('checkout.cashfree.return');
Route::post('/webhooks/cashfree', [CheckoutController::class, 'cashfreeWebhook'])->name('webhooks.cashfree');
Route::get('/checkout/order-received/{number}', [CheckoutController::class, 'thanks'])->name('checkout.thanks');
Route::post('/contact-message', [ContactController::class, 'store'])->name('contact.send')->middleware('throttle:5,10');
Route::get('/track-order', [AccountController::class, 'track'])->name('track');
Route::get('/invoice/{number}', [AccountController::class, 'invoice'])->name('invoice.download');
Route::get('/review/{number}', [ReviewRequestController::class, 'show'])->name('review.order');
Route::post('/review/{number}', [ReviewRequestController::class, 'store'])->name('review.order.store')->middleware('throttle:10,1');

Route::get('/my-account', [AccountController::class, 'index'])->name('account');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendReset'])->name('password.email')->middleware('throttle:5,1');
    Route::get('/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->name('password.update')->middleware('throttle:10,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/my-account/orders/{number}', [AccountController::class, 'order'])->name('account.order');
    Route::get('/wishlist', [AccountController::class, 'wishlist'])->name('wishlist');
    Route::post('/wishlist/{product}', [AccountController::class, 'toggleWishlist'])->name('wishlist.toggle');
});

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-{type}.xml', [SitemapController::class, 'show'])->whereIn('type', ['products', 'categories', 'pages', 'images']);
Route::get('/product_sitemap.xml', fn () => redirect('/sitemap-products.xml', 301));
Route::get('/product_cat-sitemap.xml', fn () => redirect('/sitemap-categories.xml', 301));
Route::get('/sitemap_index.xml', fn () => redirect('/sitemap.xml', 301));
Route::get('/wp-sitemap.xml', fn () => redirect('/sitemap.xml', 301));
Route::get('/robots.txt', [SitemapController::class, 'robots']);
Route::get('/feeds/google-merchant.xml', [FeedController::class, 'google'])->name('feed.google');
Route::get('/feeds/facebook-catalog.csv', [FeedController::class, 'facebook'])->name('feed.facebook');
// Google Images knows the old WordPress image URLs; send each one to the copy the importer downloaded.
Route::get('/wp-content/uploads/{path}', function (string $path) {
    $img = ProductImage::where('source_url', 'like', '%/wp-content/uploads/'.$path)->first();
    abort_unless($img, 404);

    return redirect($img->url(), 301);
})->where('path', '.+');
// Hostinger blocks PHP's symlink(), so `storage:link` can fail. Files the web server
// can't find under /storage/ come here and are read straight from storage/app/public.
Route::get('/storage/{path}', function (string $path) {
    if (str_contains($path, '..') || ! Storage::disk('public')->exists($path)) {
        return response('Not found', 404);
    }

    return response()->file(Storage::disk('public')->path($path), ['Cache-Control' => 'public, max-age=31536000']);
})->where('path', '.+')->withoutMiddleware('web')->name('storage.fallback');
Route::get('/manifest.webmanifest', [PageController::class, 'manifest'])->name('manifest');
Route::get('/{key}.txt', [SitemapController::class, 'indexNowKey'])->where('key', '[a-f0-9]{32}');

Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');
    Route::get('analytics', [Admin\AnalyticsController::class, 'index'])->name('analytics');
    Route::get('home-page', [Admin\HomePageController::class, 'edit'])->name('home.edit');
    Route::put('home-page', [Admin\HomePageController::class, 'update'])->name('home.update');
    Route::post('analytics/refresh', [Admin\AnalyticsController::class, 'refresh'])->name('analytics.refresh');
    Route::post('analytics/sitemap', [Admin\AnalyticsController::class, 'submitSitemap'])->name('analytics.sitemap');
    Route::post('analytics/index-check', [Admin\AnalyticsController::class, 'checkIndex'])->name('analytics.index-check');
    Route::post('products/personalisation', [Admin\ProductController::class, 'bulkPersonalisation'])->name('products.personalisation');
    Route::resource('products', Admin\ProductController::class)->except('show');
    Route::post('products/{product}/images', [Admin\ProductController::class, 'uploadImages'])->name('products.images');
    Route::delete('product-images/{image}', [Admin\ProductController::class, 'deleteImage'])->name('products.images.delete');
    Route::resource('categories', Admin\CategoryController::class)->except('show');
    Route::get('orders', [Admin\OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [Admin\OrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}', [Admin\OrderController::class, 'update'])->name('orders.update');
    Route::get('orders/{order}/uploads.zip', [Admin\OrderController::class, 'downloadUploads'])->name('orders.uploads');
    Route::get('orders/{order}/uploads/{upload}', [Admin\OrderController::class, 'downloadUpload'])->name('orders.upload');
    Route::get('orders/{order}/production', [Admin\OrderController::class, 'productionSheet'])->name('orders.production');
    Route::post('orders/{order}/ship', [Admin\OrderController::class, 'ship'])->name('orders.ship');
    Route::get('orders/{order}/invoice.pdf', [Admin\OrderController::class, 'invoice'])->name('orders.invoice');
    Route::post('orders/{order}/whatsapp', [Admin\OrderController::class, 'whatsapp'])->name('orders.whatsapp');
    Route::post('orders/{order}/track', [Admin\OrderController::class, 'track'])->name('orders.track');
    Route::get('orders/{order}/label', [Admin\OrderController::class, 'label'])->name('orders.label');
    Route::post('orders/{order}/cancel-shipment', [Admin\OrderController::class, 'cancelShipment'])->name('orders.cancel-shipment');
    Route::post('orders/pickup', [Admin\OrderController::class, 'pickup'])->name('orders.pickup');
    Route::post('orders/bulk', [Admin\OrderController::class, 'bulk'])->name('orders.bulk');
    Route::post('orders/delhivery-warehouse', [Admin\OrderController::class, 'delhiveryWarehouse'])->name('orders.warehouse');
    Route::resource('coupons', Admin\CouponController::class)->except('show');
    Route::resource('pages', Admin\PageController::class)->except('show');
    Route::resource('banners', Admin\BannerController::class)->except('show');
    Route::resource('redirects', Admin\RedirectController::class)->except('show');
    Route::get('reviews', [Admin\ReviewController::class, 'index'])->name('reviews.index');
    Route::get('abandoned-carts', [Admin\AbandonedCartController::class, 'index'])->name('abandoned.index');
    Route::put('reviews/{review}', [Admin\ReviewController::class, 'update'])->name('reviews.update');
    Route::delete('reviews/{review}', [Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');
    Route::get('seo', [Admin\SeoController::class, 'index'])->name('seo');
    Route::get('settings', [Admin\SettingController::class, 'edit'])->name('settings');
    Route::put('settings', [Admin\SettingController::class, 'update']);
    Route::get('messages', [Admin\MessageController::class, 'index'])->name('messages.index');
    Route::delete('messages/{message}', [Admin\MessageController::class, 'destroy'])->name('messages.destroy');
    Route::get('import', [Admin\ImportController::class, 'index'])->name('import');
    Route::post('import', [Admin\ImportController::class, 'store'])->name('import.store');
    Route::post('import/run', [Admin\ImportController::class, 'run'])->name('import.run');
    Route::get('media', [Admin\MediaController::class, 'index'])->name('media.index');
    Route::post('media', [Admin\MediaController::class, 'store'])->name('media.store');
    Route::put('media/{media}', [Admin\MediaController::class, 'update'])->name('media.update');
    Route::delete('media/{media}', [Admin\MediaController::class, 'destroy'])->name('media.destroy');
});

// CMS pages last, at their WordPress paths: /about-us/, /legal/privacy-policy-2/ ...
Route::get('/{slug}', [PageController::class, 'show'])->where('slug', '[a-z0-9\-]+(?:/[a-z0-9\-]+)*')->name('page');

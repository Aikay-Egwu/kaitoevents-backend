<?php

/**
 * DIY Shop — Public API Routes
 *
 * Loaded manually inside routes/api.php → Route::prefix('public') group
 * (NOT auto-loaded via RoutesHelper — that runs inside sanctum-admin).
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicShopController;
use App\Http\Controllers\PublicShopOrderController;
use App\Http\Controllers\PublicShopPaymentController;

Route::prefix('shop')->group(function () {

    // Categories — active-only, sorted.
    Route::get('/categories',                 [PublicShopController::class, 'categories']);
    Route::get('/categories/{slug}',          [PublicShopController::class, 'categoryShow']);

    // Products — listing with filters; detail with 4 related.
    Route::get('/products',                   [PublicShopController::class, 'productsWithCache']);
    Route::get('/products/{slug}',            [PublicShopController::class, 'productShow']);

    // Orders — POST rate-limited (30 per minute per IP).
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/orders',                [PublicShopOrderController::class, 'store']);
        Route::post('/orders/{ref}/payments', [PublicShopPaymentController::class, 'storeForOrder']);
    });
    // GET lookup (also throttled generously to prevent enumeration).
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/orders/{ref}',           [PublicShopOrderController::class, 'show']);
    });

    // Provider-signed webhook (separate throttle keyed by signature).
    Route::middleware('throttle:120,1')->group(function () {
        Route::post('/payments/webhook',      [PublicShopPaymentController::class, 'webhook']);
    });
});



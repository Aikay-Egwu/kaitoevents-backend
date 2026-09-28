<?php

/**
 * DIY Shop — Admin API Routes
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles() inside the
 * auth:sanctum + admin prefix block. All URLs here resolve as:
 *   /api/admin/shop/{path}
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShopCategoryController;
use App\Http\Controllers\ShopProductController;
use App\Http\Controllers\ShopProductImageController;
use App\Http\Controllers\ShopProductCustomisationOptionController;
use App\Http\Controllers\ShopProductReviewController;
use App\Http\Controllers\ShopOrderAdminController;
use App\Models\ShopProductImage;
use App\Models\ShopProductCustomisationOption;
use App\Models\ShopProductReview;

Route::prefix('shop')->group(function () {

    // Category CRUD (inventory categories published to the shop)
    Route::prefix('categories')->group(function () {
        Route::get('/',                     [ShopCategoryController::class, 'index']);
        Route::post('/',                    [ShopCategoryController::class, 'store']);
        Route::get('/{category}',           [ShopCategoryController::class, 'show']);
        Route::put('/{category}',           [ShopCategoryController::class, 'update']);
        Route::delete('/{category}',        [ShopCategoryController::class, 'destroy']);
    });

    // Product CRUD (inventory items published to the shop)
    Route::prefix('products')->group(function () {
        Route::get('/',                     [ShopProductController::class, 'index']);
        Route::post('/',                    [ShopProductController::class, 'store']);
        Route::get('/{product}',            [ShopProductController::class, 'show']);
        Route::put('/{product}',            [ShopProductController::class, 'update']);
        Route::delete('/{product}',         [ShopProductController::class, 'destroy']);

        // Nested upload: multipart images[] upload
        Route::post('/{product}/images',    [ShopProductImageController::class, 'store']);

        // Nested customisation options (under product)
        Route::get('/{product}/customisation-options', [ShopProductCustomisationOptionController::class, 'index']);
        Route::post('/{product}/customisation-options', [ShopProductCustomisationOptionController::class, 'store']);

        // Nested reviews (under product)
        Route::get('/{product}/reviews',    [ShopProductReviewController::class, 'index']);
        Route::post('/{product}/reviews',   [ShopProductReviewController::class, 'store']);
    });

    // Standalone image endpoints (with explicit binding)
    Route::prefix('product-images')->group(function () {
        Route::delete('/{image}', function (ShopProductImage $image) {
            return app(ShopProductImageController::class)->destroy($image);
        })->scopeBindings(['image' => ShopProductImage::class]);

        Route::post('/{image}/primary', function (ShopProductImage $image) {
            return app(ShopProductImageController::class)->setPrimary($image);
        })->scopeBindings(['image' => ShopProductImage::class]);
    });

    // Standalone customisation option endpoints
    Route::prefix('customisation-options')->group(function () {
        Route::put('/{option}', function (ShopProductCustomisationOption $option) {
            return app(ShopProductCustomisationOptionController::class)->update(request(), $option);
        });
        Route::delete('/{option}', function (ShopProductCustomisationOption $option) {
            return app(ShopProductCustomisationOptionController::class)->destroy($option);
        });
    });

    // Standalone review endpoints
    Route::prefix('reviews')->group(function () {
        Route::put('/{review}', function (ShopProductReview $review) {
            return app(ShopProductReviewController::class)->update(request(), $review);
        });
        Route::delete('/{review}', function (ShopProductReview $review) {
            return app(ShopProductReviewController::class)->destroy($review);
        });
    });

    // Admin order management
    Route::prefix('orders')->group(function () {
        Route::get('/',                     [ShopOrderAdminController::class, 'index']);
        Route::get('/{order}',              [ShopOrderAdminController::class, 'show']);
        Route::patch('/{order}/status',     [ShopOrderAdminController::class, 'updateStatus']);
        Route::patch('/{order}/notes',      [ShopOrderAdminController::class, 'patchNotes']);
    });
});

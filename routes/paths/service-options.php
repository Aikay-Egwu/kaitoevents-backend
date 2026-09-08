<?php

/**
 * Service Options API Routes
 *
 * Nested CRUD under services. Each service can have many configurable
 * options (text, number, boolean, select) with optional pricing.
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles().
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServiceOptionController;

Route::prefix('services/{service}/options')->group(function () {
    Route::get('/', [ServiceOptionController::class, 'index']);
    Route::post('/', [ServiceOptionController::class, 'store']);
    Route::put('/{option}', [ServiceOptionController::class, 'update']);
    Route::delete('/{option}', [ServiceOptionController::class, 'destroy']);
});

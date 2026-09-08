<?php

/**
 * Event Venue API Routes
 *
 * Singleton CRUD for the events_venue table. Each event has at most one
 * venue record containing logistics, room dimensions, infrastructure,
 * spatial design, table configuration, focal points, and provisions.
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles().
 */
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

// Venue CRUD (singleton — one venue per event)
Route::prefix('services')->group(function () {
        Route::get('/', [ServiceController::class, 'index']);
        Route::post('/', [ServiceController::class, 'store']);
        Route::get('/{service}', [ServiceController::class, 'show']);
        Route::put('/{service}', [ServiceController::class, 'update']);
        Route::delete('/{service}', [ServiceController::class, 'destroy']);
        Route::get('/{service}/availability', [ServiceController::class, 'checkAvailability']);
    });


<?php

/**
 * Event Task/Item Tracking API Routes
 *
 * Full CRUD for event_tracking_items (consultation to-do list).
 * Endpoints are nested under events/{event}/tracking-items.
 * Includes a dedicated status patch route for quick inline workflow updates.
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles().
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventTrackingItemController;

Route::prefix('events/{event}/tracking-items')->group(function () {
    // List all tracking items for an event (sorted by category: tasks first, items second)
    Route::get('/', [EventTrackingItemController::class, 'index']);

    // Show single tracking item
    Route::get('/{trackingItem}', [EventTrackingItemController::class, 'show']);

    // Create new tracking item
    Route::post('/', [EventTrackingItemController::class, 'store']);

    // Update tracking item (status, assignment, details)
    Route::put('/{trackingItem}', [EventTrackingItemController::class, 'update']);

    // Quick status toggle
    Route::patch('/{trackingItem}/status', [EventTrackingItemController::class, 'updateStatus']);

    // Delete tracking item
    Route::delete('/{trackingItem}', [EventTrackingItemController::class, 'destroy']);
});

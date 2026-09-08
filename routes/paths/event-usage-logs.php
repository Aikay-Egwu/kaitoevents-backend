<?php

/**
 * Event Usage Logs API routes — auto-loaded by RoutesHelper::includeRouteFiles().
 * Full CRUD + inventory-scoped sub-endpoint.
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventUsageLogController;

Route::prefix('event-usage-logs')->group(function () {
    Route::get('/',     [EventUsageLogController::class, 'index']);
    Route::post('/',    [EventUsageLogController::class, 'store']);
    Route::get('/{id}', [EventUsageLogController::class, 'show']);
    Route::put('/{id}', [EventUsageLogController::class, 'update']);
    Route::delete('/{id}', [EventUsageLogController::class, 'destroy']);
});

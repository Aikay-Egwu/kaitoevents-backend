<?php

/**
 * Repair & Maintenance Logs API routes — auto-loaded by RoutesHelper::includeRouteFiles().
 * Full CRUD for repair / PAT / service / retirement logs.
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RepairMaintenanceLogController;

Route::prefix('repair-maintenance-logs')->group(function () {
    Route::get('/',     [RepairMaintenanceLogController::class, 'index']);
    Route::post('/',    [RepairMaintenanceLogController::class, 'store']);
    Route::get('/{id}', [RepairMaintenanceLogController::class, 'show']);
    Route::put('/{id}', [RepairMaintenanceLogController::class, 'update']);
    Route::delete('/{id}', [RepairMaintenanceLogController::class, 'destroy']);
});

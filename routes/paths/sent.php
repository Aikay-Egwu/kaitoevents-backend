<?php

/**
 * User Management API Routes
 *
 * CRUD for admin users (type: Admin, Manager, Staff).
 * All new users are created with type 'Admin'.
 * Includes a dedicated change-password endpoint.
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles().
 */

use App\Http\Controllers\SentController;
use Illuminate\Support\Facades\Route;

Route::prefix('sent')->group(function () {
    // List all users
    Route::get('/', [SentController::class, 'index']);

    // Create new user (type forced to 'Admin') 
    Route::post('/', [SentController::class, 'sent']);
});

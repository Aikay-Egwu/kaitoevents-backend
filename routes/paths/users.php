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

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UsersController;

Route::prefix('users')->group(function () {
    // List all users
    Route::get('/', [UsersController::class, 'index']);

    // Create new user (type forced to 'Admin') 
    Route::post('/', [UsersController::class, 'store']);

    // Get single user
    Route::get('/{id}', [UsersController::class, 'show']);

    // Update user (name, email, type)
    Route::put('/{id}', [UsersController::class, 'update']);

    // Delete user
    Route::delete('/{id}', [UsersController::class, 'destroy']);

    // Change user password
    Route::post('/{id}/change-password', [UsersController::class, 'changePassword']);
});

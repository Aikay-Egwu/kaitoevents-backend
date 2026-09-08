<?php

/**
 * Vendor Management API Routes
 *
 * Routes for managing vendor categories, vendor types, vendors,
 * and event-vendor assignments.
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles().
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VendorCategoryController;
use App\Http\Controllers\VendorTypeController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\EventVendorController;

// Vendor Categories CRUD
Route::prefix('vendor-categories')->group(function () {
    Route::get('/', [VendorCategoryController::class, 'index']);
    Route::post('/', [VendorCategoryController::class, 'store']);
    Route::get('/{vendorCategory}', [VendorCategoryController::class, 'show']);
    Route::put('/{vendorCategory}', [VendorCategoryController::class, 'update']);
    Route::delete('/{vendorCategory}', [VendorCategoryController::class, 'destroy']);
});

// Vendor Types CRUD
Route::prefix('vendor-types')->group(function () {
    Route::get('/', [VendorTypeController::class, 'index']);
    Route::post('/', [VendorTypeController::class, 'store']);
    Route::get('/{vendorType}', [VendorTypeController::class, 'show']);
    Route::put('/{vendorType}', [VendorTypeController::class, 'update']);
    Route::delete('/{vendorType}', [VendorTypeController::class, 'destroy']);
});

// Vendors CRUD
Route::prefix('vendors')->group(function () {
    Route::get('/', [VendorController::class, 'index']);
    Route::post('/', [VendorController::class, 'store']);
    Route::get('/{vendor}', [VendorController::class, 'show']);
    Route::put('/{vendor}', [VendorController::class, 'update']);
    Route::delete('/{vendor}', [VendorController::class, 'destroy']);
});

// Event Vendor Assignments
Route::prefix('events/{event}/vendors')->group(function () {
    Route::get('/', [EventVendorController::class, 'index']);
    Route::post('/', [EventVendorController::class, 'store']);
    Route::put('/{vendor}', [EventVendorController::class, 'update']);
    Route::delete('/{vendor}', [EventVendorController::class, 'destroy']);
});

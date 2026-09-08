<?php

/**
 * Inventory Categories API Routes
 *
 * CRUD operations for inventory categories.
 * Categories are used to organize inventory items.
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles().
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InventoryCategoryController;

Route::prefix('inventory-categories')->group(function () {
    // List all categories
    Route::get('/', [InventoryCategoryController::class, 'index']);
    
    // Create new category
    Route::post('/', [InventoryCategoryController::class, 'store']);
    
    // Get single category
    Route::get('/{id}', [InventoryCategoryController::class, 'show']);
    
    // Update category
    Route::put('/{id}', [InventoryCategoryController::class, 'update']);
    
    // Delete category
    Route::delete('/{id}', [InventoryCategoryController::class, 'destroy']);
});

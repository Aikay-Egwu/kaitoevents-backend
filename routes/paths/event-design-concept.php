<?php

/**
 * Event Design Concept API Routes
 *
 * Provides CRUD access to the creative design blueprint for a given event.
 * Each event has at most one design concept (hasOne relationship), so these
 * routes operate on a single resource rather than a collection.
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles() from the /routes/paths directory.
 *
 * Endpoints:
 *   GET    /admin/events/{event}/design-concept  — Retrieve
 *   POST   /admin/events/{event}/design-concept  — Create
 *   PUT    /admin/events/{event}/design-concept  — Update
 *   DELETE /admin/events/{event}/design-concept  — Delete
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventDesignConceptController;

Route::get('/events/{event}/design-concept', [EventDesignConceptController::class, 'index']);
Route::post('/events/{event}/design-concept', [EventDesignConceptController::class, 'store']);
Route::put('/events/{event}/design-concept', [EventDesignConceptController::class, 'update']);
Route::delete('/events/{event}/design-concept', [EventDesignConceptController::class, 'destroy']);

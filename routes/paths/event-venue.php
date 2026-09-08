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

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventVenuesController;

// Venue CRUD (singleton — one venue per event)
Route::get('/events/{event}/venue', [EventVenuesController::class, 'index']);
Route::post('/events/{event}/venue', [EventVenuesController::class, 'store']);
Route::put('/events/{event}/venue', [EventVenuesController::class, 'update']);
Route::delete('/events/{event}/venue', [EventVenuesController::class, 'destroy']);

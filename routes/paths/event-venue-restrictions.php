<?php

/**
 * Event Venue Restrictions API Routes
 *
 * CRUD for the event_venue_restrictions pivot table. Each restriction links
 * an event to a restriction type with a venue_position and impact_on_design.
 * Also provides a standalone endpoint to list all restriction types for the
 * frontend dropdown.
 *
 * Auto-loaded by RoutesHelper::includeRouteFiles().
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventVenueRestrictionsController;

// List all restriction types (for dropdowns)
Route::get('/restriction-types', [EventVenueRestrictionsController::class, 'types']);

// CRUD for event-specific restrictions
Route::get('/events/{event}/restrictions', [EventVenueRestrictionsController::class, 'index']);
Route::post('/events/{event}/restrictions', [EventVenueRestrictionsController::class, 'store']);
Route::get('/events/{event}/restrictions/{restriction}', [EventVenueRestrictionsController::class, 'show']);
Route::put('/events/{event}/restrictions/{restriction}', [EventVenueRestrictionsController::class, 'update']);
Route::delete('/events/{event}/restrictions/{restriction}', [EventVenueRestrictionsController::class, 'destroy']);

<?php

use App\Http\Controllers\JobController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Job / Team Management Routes
|--------------------------------------------------------------------------
|
| Endpoints for creating event-specific teams (groups), managing their
| members + team lead, and assigning tasks to entire groups.
|
| Parent routes loader already applies:
|   'prefix' => 'api/admin',  'middleware' => 'auth:sanctum'
| So the final URLs are:
|   GET  /api/admin/events/{event}/groups
|   POST /api/admin/events/{event}/groups/{group}/tasks, etc.
|
| Automatic require via RoutesHelper::includeRouteFiles at routes/paths/
*/

Route::prefix('events/{event}/groups')->group(function () {

    // ---------- Groups (teams) ------------------------------------------
    Route::get('/', [JobController::class, 'indexGroups']);
    Route::get('/{group}', [JobController::class, 'showGroup']);
    Route::post('/', [JobController::class, 'storeGroup']);
    Route::put('/{group}', [JobController::class, 'updateGroup']);
    Route::delete('/{group}', [JobController::class, 'destroyGroup']);

    // ---------- Members --------------------------------------------------
    Route::get('/{group}/members', [JobController::class, 'listMembers']);
    Route::post('/{group}/members', [JobController::class, 'addMember']);
    Route::delete('/{group}/members/{userId}', [JobController::class, 'removeMember']);

    // ---------- Tasks (assigned to whole groups) ------------------------
    Route::get('/tasks/all', [JobController::class, 'indexTasks']);
    Route::post('/{group}/tasks', [JobController::class, 'storeTask']);
    Route::put('/{group}/tasks/{task}', [JobController::class, 'updateTask']);
    Route::delete('/{group}/tasks/{task}', [JobController::class, 'destroyTask']);
});

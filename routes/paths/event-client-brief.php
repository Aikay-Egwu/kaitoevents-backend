<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventClientBriefController;

Route::get('/events/{event}/client-brief', [EventClientBriefController::class, 'index']);
Route::post('/events/{event}/client-brief', [EventClientBriefController::class, 'store']);
Route::put('/events/{event}/client-brief', [EventClientBriefController::class, 'update']);
Route::delete('/events/{event}/client-brief', [EventClientBriefController::class, 'destroy']);
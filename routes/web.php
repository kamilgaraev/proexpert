<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return response()->json(['message' => 'Please use API endpoints for authentication'], 401);
})->name('login');

Route::get('/metrics', [App\Http\Controllers\MetricsController::class, 'metrics']);
Route::get('/test-metrics', [App\Http\Controllers\MetricsController::class, 'test']);

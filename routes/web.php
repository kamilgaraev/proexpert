<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return response()->json(['message' => 'Please use API endpoints for authentication'], 401);
})->name('login');

// Простой тест без контроллера
Route::get('/simple-test', function () {
    return response("# Simple test\ntest_metric 1\n", 200, [
        'Content-Type' => 'text/plain; charset=utf-8'
    ]);
});

Route::get('/metrics', [App\Http\Controllers\MetricsController::class, 'metrics']);
Route::get('/test-metrics', [App\Http\Controllers\MetricsController::class, 'test']);

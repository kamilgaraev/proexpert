<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\Auth\AuthController;

Route::prefix('auth')->name('landing.auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    
    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
}); 
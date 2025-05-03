<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    
    Route::middleware(['auth:api_admin', 'auth.jwt:api_admin'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
}); 
<?php

use Illuminate\Support\Facades\Route;
// Контроллер для мобильного API будет создан позже
use App\Http\Controllers\Api\V1\Mobile\Auth\AuthController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    
    // Добавляем защищенные маршруты, требующие аутентификации
    Route::middleware('auth:api_mobile')->group(function() {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
}); 
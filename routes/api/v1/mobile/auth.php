<?php

use Illuminate\Support\Facades\Route;
// Контроллер для мобильного API будет создан позже
// use App\Http\Controllers\Api\V1\Mobile\Auth\AuthController;

Route::prefix('mobile/auth')->name('mobile.auth.')->group(function () {
    Route::post('login', function () {
        return response()->json([
            'success' => false,
            'message' => 'API для мобильных приложений находится в разработке',
        ], 501);
    })->name('login');
    
    /*
    Route::middleware('auth:api_mobile')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
    */
}); 
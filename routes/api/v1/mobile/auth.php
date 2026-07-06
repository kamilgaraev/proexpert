<?php

use App\Http\Controllers\Api\V1\Mobile\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:auth')->post('login', [AuthController::class, 'login'])->name('mobile.login');

    Route::middleware(['auth.jwt:api_mobile', 'auth.session', 'throttle:dashboard'])
        ->post('refresh', [AuthController::class, 'refresh'])
        ->name('refresh');

    Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'auth.session', 'can:access-mobile-app'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('switch-organization', [AuthController::class, 'switchOrganization'])->name('switch-organization');
    });
});

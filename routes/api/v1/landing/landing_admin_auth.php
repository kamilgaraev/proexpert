<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\Auth\LandingAdminAuthController;

Route::prefix('landingAdminAuth')->name('landingAdminAuth.')->group(function () {
    Route::middleware('throttle:auth')->post('login', [LandingAdminAuthController::class, 'login'])->name('login');

    Route::middleware(['auth.jwt:api_landing_admin', 'auth.session', 'throttle:dashboard'])
        ->post('refresh', [LandingAdminAuthController::class, 'refresh'])
        ->name('refresh');

    Route::middleware([
        'auth:api_landing_admin',
        'auth.jwt:api_landing_admin',
        'auth.session',
        'throttle:dashboard',
    ])->group(function () {
        Route::get('me', [LandingAdminAuthController::class, 'me'])->name('me');
        Route::post('logout', [LandingAdminAuthController::class, 'logout'])->name('logout');
    });
});

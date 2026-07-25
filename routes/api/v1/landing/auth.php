<?php

use App\Http\Controllers\Api\V1\Landing\Auth\AuthController;
use App\Http\Controllers\Api\V1\Landing\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Landing\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->name('verification.verify');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware(['origin.web:lk', 'throttle:auth'])->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('landing.login');
        Route::post('password/email', [AuthController::class, 'forgotPassword'])->name('password.email');
        Route::post('password/reset', [AuthController::class, 'resetPassword'])->name('password.reset');
    });

    Route::middleware(['auth.web-refresh:lk', 'origin.web:lk', 'csrf.web:lk', 'throttle:web-refresh'])
        ->post('refresh', [AuthController::class, 'refresh'])
        ->name('refresh');

    Route::middleware(['auth.web-refresh:lk', 'origin.web:lk,true', 'throttle:web-refresh'])
        ->get('csrf', [AuthController::class, 'csrf'])
        ->name('csrf');

    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'auth.session'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
        Route::post('logout', [AuthController::class, 'logout'])
            ->middleware('csrf.web:lk')
            ->name('logout');

        Route::post('email/resend', [EmailVerificationController::class, 'resend'])->name('verification.resend');
        Route::get('email/check', [EmailVerificationController::class, 'check'])->name('verification.check');
    });
});

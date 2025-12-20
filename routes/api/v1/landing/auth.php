<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\Auth\AuthController;
use App\Http\Controllers\Api\V1\Landing\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Landing\ProfileController;

Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->name('verification.verify');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('landing.login');
    
    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])->name('verification.resend');
        Route::get('email/check', [EmailVerificationController::class, 'check'])->name('verification.check');
    });
}); 
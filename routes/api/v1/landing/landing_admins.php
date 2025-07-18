<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\LandingAdminController;

Route::middleware(['auth:api_landing_admin', 'auth.jwt:api_landing_admin'])
    ->prefix('landing-admins')
    ->name('landingAdmins.')
    ->group(function () {
        Route::get('/', [LandingAdminController::class, 'index'])->name('index');
        Route::post('/', [LandingAdminController::class, 'store'])->name('store');
        Route::get('/{landingAdmin}', [LandingAdminController::class, 'show'])->name('show');
        Route::put('/{landingAdmin}', [LandingAdminController::class, 'update'])->name('update');
        Route::delete('/{landingAdmin}', [LandingAdminController::class, 'destroy'])->name('destroy');
    }); 
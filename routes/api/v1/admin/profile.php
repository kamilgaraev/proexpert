<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ProfileController;

Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])
    ->prefix('profile')
    ->name('profile.')
    ->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::patch('/', [ProfileController::class, 'update'])->name('update');
        Route::put('/', [ProfileController::class, 'update'])->name('update.put');
        Route::patch('/onboarding', [ProfileController::class, 'updateOnboarding'])->name('update.onboarding');
    });

// Альтернативный роут для совместимости с фронтендом
Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])
    ->prefix('profile-settings')
    ->name('profile-settings.')
    ->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::patch('/', [ProfileController::class, 'update'])->name('update');
        Route::put('/', [ProfileController::class, 'update'])->name('update.put');
    }); 
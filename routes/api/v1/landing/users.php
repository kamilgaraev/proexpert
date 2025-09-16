<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\UserController;
use App\Http\Controllers\Api\V1\Landing\AdminPanelUserController;

Route::middleware(['auth:api_landing', 'authorize:users.manage', 'interface:lk']) 
    ->prefix('users') // Префикс для управления администраторами
    ->name('users.') // Префикс имени маршрута
    ->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');          // GET /users
        Route::post('/', [UserController::class, 'store'])->name('store');          // POST /users
        Route::get('/{user}', [UserController::class, 'show'])->name('show');            // GET /users/{user}
        Route::match(['put', 'patch'], '/{user}', [UserController::class, 'update'])->name('update');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');    // DELETE /users/{user}
    });

// Можно было использовать Route::apiResource('users', UserController::class);
// но явное определение дает больше контроля над именами и методами.

// --- Маршруты для управления Пользователями Админ-Панели (web_admin, accountant, etc.) ---
Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context', 'authorize:users.manage_admin', 'interface:lk'])
    ->prefix('adminPanelUsers') // Новый префикс
    ->name('adminPanelUsers.')  // Новый неймспейс имен
    ->group(function () {
        Route::post('/', [AdminPanelUserController::class, 'store'])->name('store');
        Route::get('/', [AdminPanelUserController::class, 'index'])->name('index');
        Route::get('/{user}', [AdminPanelUserController::class, 'show'])->name('show');
        Route::match(['put', 'patch'], '/{user}', [AdminPanelUserController::class, 'update'])->name('update');
        Route::delete('/{user}', [AdminPanelUserController::class, 'destroy'])->name('destroy');
    });

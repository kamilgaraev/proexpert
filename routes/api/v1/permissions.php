<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\UserPermissionsController;

/*
|--------------------------------------------------------------------------
| User Permissions API Routes
|--------------------------------------------------------------------------
|
| Маршруты для получения прав пользователя
| Используются всеми интерфейсами (Landing/LK, Admin Panel, Mobile)
|
*/

// Для всех интерфейсов (будет работать с любым guard)
Route::group([], function () {
    // Получить все права и роли текущего пользователя
    Route::get('/permissions', [UserPermissionsController::class, 'index'])->name('permissions.index');
    
    // Проверить конкретное право
    Route::post('/permissions/check', [UserPermissionsController::class, 'check'])->name('permissions.check');
});

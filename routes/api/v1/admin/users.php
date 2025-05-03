<?php

use App\Http\Controllers\Api\V1\Admin\UserManagementController;
use Illuminate\Support\Facades\Route;
 
// Используем 'users' как ресурс, но контроллер отвечает за управление прорабами
Route::apiResource('users', UserManagementController::class);

// Добавляем маршруты для блокировки/разблокировки
Route::post('users/{user}/block', [UserManagementController::class, 'block'])->name('users.block');
Route::post('users/{user}/unblock', [UserManagementController::class, 'unblock'])->name('users.unblock'); 
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\UserManagementController;

/*
|--------------------------------------------------------------------------
| Admin API V1 User Management Routes
|--------------------------------------------------------------------------
|
| Маршруты для управления прорабами в административной панели.
|
*/

// Группа уже защищена middleware в RouteServiceProvider

// User Management (Foremen)
Route::apiResource('users', UserManagementController::class)->except(['destroy']);
Route::post('users/{user}/block', [UserManagementController::class, 'block'])->name('users.block');
Route::post('users/{user}/unblock', [UserManagementController::class, 'unblock'])->name('users.unblock'); 
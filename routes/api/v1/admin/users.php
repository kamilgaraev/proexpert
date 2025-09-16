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

// User Management (Foremen) - с детальными правами
Route::get('users', [UserManagementController::class, 'index'])
    ->middleware('authorize:admin.users.view')
    ->name('users.index');
Route::post('users', [UserManagementController::class, 'store'])
    ->middleware('authorize:admin.users.create')
    ->name('users.store');
Route::get('users/{user}', [UserManagementController::class, 'show'])
    ->middleware('authorize:admin.users.view')
    ->name('users.show');
Route::put('users/{user}', [UserManagementController::class, 'update'])
    ->middleware('authorize:admin.users.edit')
    ->name('users.update');
Route::patch('users/{user}', [UserManagementController::class, 'update'])
    ->middleware('authorize:admin.users.edit')
    ->name('users.patch');

Route::post('users/{user}/block', [UserManagementController::class, 'block'])
    ->middleware('authorize:admin.users.block')
    ->name('users.block');
Route::post('users/{user}/unblock', [UserManagementController::class, 'unblock'])
    ->middleware('authorize:admin.users.block')
    ->name('users.unblock'); 
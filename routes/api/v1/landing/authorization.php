<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Authorization\Http\Controllers\Api\V1\Landing\CustomRoleController;
use App\Http\Controllers\Api\V1\Landing\RolesComparisonController;
use App\Http\Controllers\Api\V1\Landing\SystemRoleController;

/*
|--------------------------------------------------------------------------
| Authorization API Routes
|--------------------------------------------------------------------------
|
| Роуты для новой системы авторизации
|
*/

Route::prefix('authorization')
    ->middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
    ->group(function () {
        
        // Сравнение ролей (доступен всем аутентифицированным пользователям)
        Route::get('roles/comparison', [RolesComparisonController::class, 'comparison'])
            ->name('roles.comparison');

        // Системные роли
        Route::get('system-roles', [SystemRoleController::class, 'index'])
            ->name('system-roles.index');
        
        // Кастомные роли организации
        Route::prefix('custom-roles')->group(function () {
            
            // Получить все роли организации
            Route::get('/', [CustomRoleController::class, 'index'])
                ->name('custom-roles.index');
            
            // Создать новую роль
            Route::post('/', [CustomRoleController::class, 'store'])
                ->name('custom-roles.store');
            
            // Получить детали роли
            Route::get('/{role}', [CustomRoleController::class, 'show'])
                ->name('custom-roles.show');
            
            // Обновить роль
            Route::put('/{role}', [CustomRoleController::class, 'update'])
                ->name('custom-roles.update');
            
            // Удалить роль
            Route::delete('/{role}', [CustomRoleController::class, 'destroy'])
                ->name('custom-roles.destroy');
            
            // Клонировать роль
            Route::post('/{role}/clone', [CustomRoleController::class, 'clone'])
                ->name('custom-roles.clone');
            
            // Получить доступные права для создания роли
            Route::get('/permissions/available', [CustomRoleController::class, 'getAvailablePermissions'])
                ->name('custom-roles.available-permissions');
            
            // Получить пользователей с ролью
            Route::get('/{role}/users', [CustomRoleController::class, 'getUsers'])
                ->name('custom-roles.users');
            
            // Назначить роль пользователю
            Route::post('/{role}/assign', [CustomRoleController::class, 'assignToUser'])
                ->name('custom-roles.assign');
        });
    });

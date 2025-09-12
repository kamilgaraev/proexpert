<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\UserInvitationController;
use App\Http\Controllers\Api\V1\Landing\OrganizationUserController;
use App\Http\Controllers\Api\V1\Landing\CustomUserManagementController;
// Новая система авторизации
use App\Domain\Authorization\Http\Controllers\Api\V1\Landing\CustomRoleController;

// === НОВАЯ СИСТЕМА АВТОРИЗАЦИИ - УПРАВЛЕНИЕ РОЛЯМИ ===
// Интеграция кастомных ролей в систему управления пользователями
Route::middleware(['authorize:roles.view_custom,organization'])
    ->prefix('custom-roles')->group(function () {
        
        // Получить все роли организации 
        Route::get('/', [CustomRoleController::class, 'index'])
            ->name('custom-roles.index');
            
        // Получить детали роли
        Route::get('/{role}', [CustomRoleController::class, 'show'])
            ->name('custom-roles.show');
            
        // Получить доступные права для создания роли
        Route::get('/permissions/available', [CustomRoleController::class, 'getAvailablePermissions'])
            ->name('custom-roles.available-permissions');
            
        // Получить пользователей с ролью
        Route::get('/{role}/users', [CustomRoleController::class, 'getUsers'])
            ->name('custom-roles.users');
            
        // Управление ролями (только для владельцев и админов организации)
        Route::middleware(['authorize:roles.create_custom,organization'])->group(function () {
            // Создать новую роль
            Route::post('/', [CustomRoleController::class, 'store'])
                ->name('custom-roles.store');
        });
        
        Route::middleware(['authorize:roles.manage_custom,organization'])->group(function () {
            // Обновить роль
            Route::put('/{role}', [CustomRoleController::class, 'update'])
                ->name('custom-roles.update');
                
            // Удалить роль
            Route::delete('/{role}', [CustomRoleController::class, 'destroy'])
                ->name('custom-roles.destroy');
                
            // Клонировать роль
            Route::post('/{role}/clone', [CustomRoleController::class, 'clone'])
                ->name('custom-roles.clone');
        });
        
        // Назначение ролей пользователям
        Route::middleware(['authorize:users.manage_roles,organization'])->group(function () {
            // Назначить роль пользователю
            Route::post('/{role}/assign', [CustomRoleController::class, 'assignToUser'])
                ->name('custom-roles.assign');
                
            // Отозвать роль у пользователя
            Route::delete('/{role}/unassign', [CustomRoleController::class, 'unassignFromUser'])
                ->name('custom-roles.unassign');
        });
    });

// === ПРИГЛАШЕНИЯ ПОЛЬЗОВАТЕЛЕЙ ===
Route::prefix('invitations')->group(function () {
    Route::get('/', [UserInvitationController::class, 'index']);
    Route::get('/{invitationId}', [UserInvitationController::class, 'show']);
    Route::get('/stats/overview', [UserInvitationController::class, 'stats']);
    
    Route::middleware(['authorize:users.invite,organization'])->group(function () {
        // Создать приглашение (обновлено для поддержки кастомных ролей)
        Route::post('/', [UserInvitationController::class, 'store']);
        Route::post('/{invitationId}/resend', [UserInvitationController::class, 'resend']);
        Route::delete('/{invitationId}', [UserInvitationController::class, 'destroy']);
    });
});

// === УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ ОРГАНИЗАЦИИ ===
Route::prefix('organization-users')->group(function () {
    Route::get('/', [OrganizationUserController::class, 'index']);
    Route::get('/{userId}', [OrganizationUserController::class, 'show']);
    
    Route::middleware(['authorize:users.manage,organization'])->group(function () {
        Route::put('/{userId}', [OrganizationUserController::class, 'update']);
        Route::delete('/{userId}', [OrganizationUserController::class, 'destroy']);
        Route::post('/{userId}/toggle-status', [OrganizationUserController::class, 'toggleStatus']);
    });
    
    // Управление ролями пользователей (новая система)
    Route::middleware(['authorize:users.manage_roles,organization'])->group(function () {
        // Получить роли пользователя
        Route::get('/{userId}/roles', [OrganizationUserController::class, 'getRoles']);
        // Обновить роли пользователя (принимает массив custom_role_ids)
        Route::post('/{userId}/roles', [CustomUserManagementController::class, 'updateUserCustomRoles']);
        // Назначить кастомную роль пользователю
        Route::post('/{userId}/assign-role/{roleId}', [CustomUserManagementController::class, 'assignCustomRole']);
        // Отозвать кастомную роль у пользователя
        Route::delete('/{userId}/unassign-role/{roleId}', [CustomUserManagementController::class, 'unassignCustomRole']);
    });
});

Route::get('/user-limits', [CustomUserManagementController::class, 'getUserLimits']);

// === СОЗДАНИЕ ПОЛЬЗОВАТЕЛЕЙ С КАСТОМНЫМИ РОЛЯМИ ===
Route::middleware(['authorize:users.manage,organization'])->group(function () {
    // Создать пользователя с кастомными ролями
    Route::post('/create-user-with-custom-roles', [CustomUserManagementController::class, 'createUserWithCustomRoles'])
        ->name('create-user-with-custom-roles');
});

// === ДОПОЛНИТЕЛЬНЫЕ ЭНДПОИНТЫ ДЛЯ ИНТЕГРАЦИИ НОВОЙ СИСТЕМЫ ===

// Получить системные роли и кастомные роли для селектов
Route::get('/available-roles', [CustomUserManagementController::class, 'getAvailableRoles'])
    ->middleware(['authorize:roles.view_custom,organization']);

// Получить доступные права для создания кастомных ролей
Route::get('/available-permissions', [CustomRoleController::class, 'getAvailablePermissions'])
    ->middleware(['authorize:roles.view_custom,organization']);

// Публичные маршруты для приглашений (без аутентификации)
Route::get('/invitation/{token}', [UserInvitationController::class, 'getByToken'])->name('invitation.get');
Route::post('/invitation/{token}/accept', [UserInvitationController::class, 'accept'])->name('invitation.accept'); 
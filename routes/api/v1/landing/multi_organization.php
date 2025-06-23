<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\MultiOrganizationController;

Route::middleware(['auth:api_landing', 'jwt.auth', 'organization.context', 'module.access:multi_organization'])
    ->prefix('multi-organization')
    ->name('multiOrganization.')
    ->group(function () {
        
        // Проверка доступности модуля
        Route::get('/check-availability', [MultiOrganizationController::class, 'checkAvailability'])
            ->withoutMiddleware(['module.access:multi_organization'])
            ->name('checkAvailability');
        
        // Получение иерархии организаций
        Route::get('/hierarchy', [MultiOrganizationController::class, 'getHierarchy'])
            ->name('hierarchy');
        
        // Получение доступных организаций
        Route::get('/accessible', [MultiOrganizationController::class, 'getAccessibleOrganizations'])
            ->name('accessible');
        
        // Получение данных организации
        Route::get('/organization/{organizationId}', [MultiOrganizationController::class, 'getOrganizationData'])
            ->name('organizationData');
        
        // Переключение контекста организации
        Route::post('/switch-context', [MultiOrganizationController::class, 'switchOrganizationContext'])
            ->name('switchContext');
        
        // Только для владельцев организации
        Route::middleware(['role:organization_owner'])
            ->group(function () {
                
                // Создание холдинга
                Route::post('/create-holding', [MultiOrganizationController::class, 'createHolding'])
                    ->name('createHolding');
                
                // Добавление дочерней организации
                Route::post('/add-child', [MultiOrganizationController::class, 'addChildOrganization'])
                    ->name('addChild');
            });
    }); 
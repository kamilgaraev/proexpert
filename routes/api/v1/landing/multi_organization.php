<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\MultiOrganizationController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingDashboardController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingProjectsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingContractsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingFilterController;

Route::middleware(['auth:api_landing', 'jwt.auth', 'organization.context', 'module.access:multi-organization'])
    ->prefix('multi-organization')
    ->name('multiOrganization.')
    ->group(function () {
        
        // Проверка доступности модуля
        Route::get('/check-availability', [MultiOrganizationController::class, 'checkAvailability'])
            ->withoutMiddleware(['module.access:multi-organization'])
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
        
        Route::get('/dashboard-v2', [HoldingDashboardController::class, 'index'])
            ->name('dashboardV2');
        
        Route::get('/dashboard', [MultiOrganizationController::class, 'getHoldingDashboard'])
            ->name('dashboard');

        Route::get('/filter-options', [HoldingFilterController::class, 'getFilterOptions'])
            ->name('filterOptions');

        Route::get('/projects', [HoldingProjectsController::class, 'index'])
            ->name('projects.index');
        Route::get('/projects/{projectId}', [HoldingProjectsController::class, 'show'])
            ->name('projects.show');

        Route::get('/contracts-v2', [HoldingContractsController::class, 'index'])
            ->name('contractsV2.index');

        Route::get('/child-organizations', [MultiOrganizationController::class, 'getChildOrganizations'])
            ->name('getChildOrganizations');

        Route::get('/role-templates', [MultiOrganizationController::class, 'getRoleTemplates'])
            ->name('getRoleTemplates');

        // Только для владельцев организации
        Route::middleware(['authorize:multi-organization.manage'])
            ->group(function () {
                
                // Создание холдинга
                Route::post('/create-holding', [MultiOrganizationController::class, 'createHolding'])
                    ->name('createHolding');
                
                // Добавление дочерней организации
                Route::post('/add-child', [MultiOrganizationController::class, 'addChildOrganization'])
                    ->name('addChild');

                Route::put('/child-organizations/{childOrgId}', [MultiOrganizationController::class, 'updateChildOrganization'])
                    ->name('updateChildOrganization');

                Route::delete('/child-organizations/{childOrgId}', [MultiOrganizationController::class, 'deleteChildOrganization'])
                    ->name('deleteChildOrganization');

                Route::get('/child-organizations/{childOrgId}/stats', [MultiOrganizationController::class, 'getChildOrganizationStats'])
                    ->name('getChildOrganizationStats');

                Route::get('/child-organizations/{childOrgId}/roles', [MultiOrganizationController::class, 'getChildOrganizationRoles'])
                    ->name('getChildOrganizationRoles');

                Route::get('/child-organizations/{childOrgId}/users', [MultiOrganizationController::class, 'getChildOrganizationUsers'])
                    ->name('getChildOrganizationUsers');

                Route::post('/child-organizations/{childOrgId}/users', [MultiOrganizationController::class, 'addUserToChildOrganization'])
                    ->name('addUserToChildOrganization');

                Route::post('/child-organizations/{childOrgId}/users/bulk', [MultiOrganizationController::class, 'createBulkUsers'])
                    ->name('createBulkUsers');

                Route::put('/child-organizations/{childOrgId}/users/{userId}', [MultiOrganizationController::class, 'updateUserInChildOrganization'])
                    ->name('updateUserInChildOrganization');

                Route::delete('/child-organizations/{childOrgId}/users/{userId}', [MultiOrganizationController::class, 'removeUserFromChildOrganization'])
                    ->name('removeUserFromChildOrganization');

                Route::put('/holding-settings', [MultiOrganizationController::class, 'updateHoldingSettings'])
                    ->name('updateHoldingSettings');
            });

        // Сводные отчёты по холдингу
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/contracts', [MultiOrganizationController::class, 'getHoldingContracts'])
                ->name('contracts');
            Route::get('/contracts/summary', [MultiOrganizationController::class, 'getHoldingContractsSummary'])
                ->name('contractsSummary');
            Route::get('/acts', [MultiOrganizationController::class, 'getHoldingActs'])
                ->name('acts');
            Route::get('/movements', [MultiOrganizationController::class, 'getHoldingMovements'])
                ->name('movements');
        });

        Route::get('/summary', [App\Http\Controllers\Api\V1\Landing\HoldingSummaryController::class, 'summary'])
            ->name('summary');
    }); 
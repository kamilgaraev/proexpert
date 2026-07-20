<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\MultiOrganizationController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingDashboardController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingProjectsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingContractsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingFilterController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingReportsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingLegalArchiveController;

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
            ->middleware(['authorize:multi-organization.view'])
            ->name('hierarchy');
        
        // Получение доступных организаций
        Route::get('/accessible', [MultiOrganizationController::class, 'getAccessibleOrganizations'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('accessible');
        
        // Получение данных организации
        Route::get('/organization/{organizationId}', [MultiOrganizationController::class, 'getOrganizationData'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('organizationData');
        
        // Переключение контекста организации
        Route::post('/switch-context', [MultiOrganizationController::class, 'switchOrganizationContext'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('switchContext');
        
        Route::get('/dashboard-v2', [HoldingDashboardController::class, 'index'])
            ->middleware(['authorize:multi-organization.dashboard'])
            ->name('dashboardV2');
        
        Route::get('/dashboard', [MultiOrganizationController::class, 'getHoldingDashboard'])
            ->middleware(['authorize:multi-organization.dashboard'])
            ->name('dashboard');

        Route::get('/filter-options', [HoldingFilterController::class, 'getFilterOptions'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('filterOptions');

        Route::get('/projects', [HoldingProjectsController::class, 'index'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('projects.index');
        Route::get('/projects/{projectId}', [HoldingProjectsController::class, 'show'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('projects.show');

        Route::get('/contracts-v2', [HoldingContractsController::class, 'index'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('contractsV2.index');
        Route::get('/contracts/{contractId}', [HoldingContractsController::class, 'show'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('contracts.show');
        Route::get('/legal-archive/contracts/{contractId}', [HoldingLegalArchiveController::class, 'show'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('legalArchive.contracts.show');
        Route::get('/legal-archive/contracts/{contractId}/versions/{versionId}/preview', [HoldingLegalArchiveController::class, 'preview'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('legalArchive.versions.preview');
        Route::get('/legal-archive/contracts/{contractId}/versions/{versionId}/download', [HoldingLegalArchiveController::class, 'download'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('legalArchive.versions.download');

        Route::get('/child-organizations', [MultiOrganizationController::class, 'getChildOrganizations'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('getChildOrganizations');

        Route::get('/role-templates', [MultiOrganizationController::class, 'getRoleTemplates'])
            ->middleware(['authorize:multi-organization.view'])
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
            Route::get('/projects-summary', [HoldingReportsController::class, 'projectsSummary'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('projects-summary');
            Route::get('/contracts-summary', [HoldingReportsController::class, 'contractsSummary'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('contracts-summary');
            Route::get('/intra-group', [HoldingReportsController::class, 'intraGroup'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('intra-group');
            Route::get('/consolidated', [HoldingReportsController::class, 'consolidated'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('consolidated');
            Route::get('/detailed-contracts', [HoldingReportsController::class, 'detailedContracts'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('detailed-contracts');
            
            Route::get('/contracts', [MultiOrganizationController::class, 'getHoldingContracts'])
                ->middleware(['authorize:multi-organization.reports.financial'])
                ->name('contracts');
            Route::get('/contracts/summary', [MultiOrganizationController::class, 'getHoldingContractsSummary'])
                ->middleware(['authorize:multi-organization.reports.financial'])
                ->name('contractsSummary');
            Route::get('/acts', [MultiOrganizationController::class, 'getHoldingActs'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('acts');
            Route::get('/movements', [MultiOrganizationController::class, 'getHoldingMovements'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('movements');
        });

        Route::get('/summary', [App\Http\Controllers\Api\V1\Landing\HoldingSummaryController::class, 'summary'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('summary');
    });

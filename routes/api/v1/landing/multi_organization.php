<?php

use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingContractsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingDashboardController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingFilterController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingLegacyReportsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingLegalArchiveController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingOrganizationRolesController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingOrganizationsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingOrganizationUsersController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingProjectsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingReportsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context', 'module.access:multi-organization'])
    ->prefix('multi-organization')
    ->name('multiOrganization.')
    ->group(function () {

        // Проверка доступности модуля
        Route::get('/check-availability', [HoldingOrganizationsController::class, 'checkAvailability'])
            ->withoutMiddleware(['module.access:multi-organization'])
            ->name('checkAvailability');

        // Получение иерархии организаций
        Route::get('/hierarchy', [HoldingOrganizationsController::class, 'getHierarchy'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('hierarchy');

        // Получение доступных организаций
        Route::get('/accessible', [HoldingOrganizationsController::class, 'getAccessibleOrganizations'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('accessible');

        // Получение данных организации
        Route::get('/organization/{organizationId}', [HoldingOrganizationsController::class, 'getOrganizationData'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('organizationData');

        // Переключение контекста организации
        Route::post('/switch-context', [HoldingOrganizationsController::class, 'switchOrganizationContext'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('switchContext');

        Route::get('/dashboard-v2', [HoldingDashboardController::class, 'index'])
            ->middleware(['authorize:multi-organization.dashboard'])
            ->name('dashboardV2');

        Route::get('/dashboard', [HoldingLegacyReportsController::class, 'dashboard'])
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

        Route::get('/child-organizations', [HoldingOrganizationsController::class, 'getChildOrganizations'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('getChildOrganizations');

        Route::get('/role-templates', [HoldingOrganizationRolesController::class, 'templates'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('getRoleTemplates');

        // Только для владельцев организации
        Route::middleware(['authorize:multi-organization.manage'])
            ->group(function () {

                // Создание холдинга
                Route::post('/create-holding', [HoldingOrganizationsController::class, 'createHolding'])
                    ->name('createHolding');

                // Добавление дочерней организации
                Route::post('/add-child', [HoldingOrganizationsController::class, 'addChildOrganization'])
                    ->name('addChild');

                Route::put('/child-organizations/{childOrgId}', [HoldingOrganizationsController::class, 'updateChildOrganization'])
                    ->name('updateChildOrganization');

                Route::delete('/child-organizations/{childOrgId}', [HoldingOrganizationsController::class, 'deleteChildOrganization'])
                    ->name('deleteChildOrganization');

                Route::get('/child-organizations/{childOrgId}/stats', [HoldingOrganizationsController::class, 'getChildOrganizationStats'])
                    ->name('getChildOrganizationStats');

                Route::get('/child-organizations/{childOrgId}/roles', [HoldingOrganizationRolesController::class, 'index'])
                    ->name('getChildOrganizationRoles');

                Route::get('/child-organizations/{childOrgId}/users', [HoldingOrganizationUsersController::class, 'index'])
                    ->name('getChildOrganizationUsers');

                Route::post('/child-organizations/{childOrgId}/users', [HoldingOrganizationUsersController::class, 'store'])
                    ->name('addUserToChildOrganization');

                Route::post('/child-organizations/{childOrgId}/users/bulk', [HoldingOrganizationUsersController::class, 'bulk'])
                    ->name('createBulkUsers');

                Route::put('/child-organizations/{childOrgId}/users/{userId}', [HoldingOrganizationUsersController::class, 'update'])
                    ->name('updateUserInChildOrganization');

                Route::delete('/child-organizations/{childOrgId}/users/{userId}', [HoldingOrganizationUsersController::class, 'destroy'])
                    ->name('removeUserFromChildOrganization');

                Route::put('/holding-settings', [HoldingOrganizationsController::class, 'updateHoldingSettings'])
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

            Route::get('/contracts', [HoldingLegacyReportsController::class, 'contracts'])
                ->middleware(['authorize:multi-organization.reports.financial'])
                ->name('contracts');
            Route::get('/contracts/summary', [HoldingLegacyReportsController::class, 'contractsSummary'])
                ->middleware(['authorize:multi-organization.reports.financial'])
                ->name('contractsSummary');
            Route::get('/acts', [HoldingLegacyReportsController::class, 'acts'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('acts');
            Route::get('/movements', [HoldingLegacyReportsController::class, 'movements'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('movements');
        });

        Route::get('/summary', [App\Http\Controllers\Api\V1\Landing\HoldingSummaryController::class, 'summary'])
            ->middleware(['authorize:multi-organization.view'])
            ->name('summary');
    });

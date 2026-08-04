<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Access\ReportingPermissionMatrix;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\BudgetPlanFactReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ProjectMarginReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportCatalogController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportDrillDownController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportExportController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportRowsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportRunController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSavedViewController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSubscriptionController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportWorkspacePreferencesController;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\RenderReportErrors;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/reports')
    ->name('admin.reports.')
    ->middleware(AdminRouteStack::middleware([RenderReportErrors::class]))
    ->group(function (): void {
        $view = ReportingPermissionMatrix::middleware(ReportOperation::VIEW);
        $manage = ReportingPermissionMatrix::middleware(ReportOperation::MANAGE);

        $resourceAccess = AuthorizeReportDefinitionAccess::class;
        $workspaceModule = 'module.access:reports';

        Route::get('/catalog', ReportCatalogController::class)
            ->middleware($resourceAccess)
            ->name('catalog');
        Route::get('/workspace', [ReportWorkspacePreferencesController::class, 'show'])
            ->middleware([$workspaceModule, ...$view])
            ->name('workspace.show');
        Route::post('/workspace/recent/{reportCode}', [ReportWorkspacePreferencesController::class, 'recordRecent'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('workspace.recent.store');
        Route::put('/workspace/favourites', [ReportWorkspacePreferencesController::class, 'setFavourites'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('workspace.favourites.update');
        Route::patch('/workspace/preferences', [ReportWorkspacePreferencesController::class, 'updatePreferences'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('workspace.preferences.update');
        Route::get('/saved-views', [ReportSavedViewController::class, 'index'])
            ->middleware([$workspaceModule, ...$view])->name('saved-views.index');
        Route::post('/saved-views', [ReportSavedViewController::class, 'store'])
            ->middleware([$workspaceModule, ...$manage])->name('saved-views.store');
        Route::get('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'show'])
            ->middleware([$workspaceModule, ...$view])->name('saved-views.show');
        Route::patch('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'update'])
            ->middleware([$workspaceModule, ...$manage])->name('saved-views.update');
        Route::delete('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'destroy'])
            ->middleware([$workspaceModule, ...$manage])->name('saved-views.destroy');
        Route::post('/saved-views/{savedViewId}/set-default', [ReportSavedViewController::class, 'setDefault'])
            ->middleware([$workspaceModule, ...$manage])->name('saved-views.set-default');
        Route::get('/subscriptions', [ReportSubscriptionController::class, 'index'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.index');
        Route::post('/subscriptions', [ReportSubscriptionController::class, 'store'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.store');
        Route::patch('/subscriptions/{subscriptionId}', [ReportSubscriptionController::class, 'update'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.update');
        Route::delete('/subscriptions/{subscriptionId}', [ReportSubscriptionController::class, 'destroy'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.destroy');
        Route::post('/subscriptions/{subscriptionId}/pause', [ReportSubscriptionController::class, 'pause'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.pause');
        Route::post('/subscriptions/{subscriptionId}/resume', [ReportSubscriptionController::class, 'resume'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.resume');
        Route::post('/subscriptions/{subscriptionId}/run-now', [ReportSubscriptionController::class, 'runNow'])
            ->middleware([$workspaceModule, ...$manage])
            ->name('subscriptions.run-now');
        Route::post('/{reportCode}/runs', [ReportRunController::class, 'store'])
            ->middleware($resourceAccess)
            ->name('runs.store');
        Route::post('/projects/{project}/budget-plan-fact/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'budget_plan_fact')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-budget-plan-fact.runs.store');
        Route::get('/projects/{project}/budget-plan-fact/options', BudgetPlanFactReportOptionsController::class)
            ->defaults('reportCode', 'budget_plan_fact')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-budget-plan-fact.options');
        Route::post('/projects/{project}/project-margin/runs', [ReportRunController::class, 'store'])
            ->defaults('reportCode', 'project_margin')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-margin.runs.store');
        Route::get('/projects/{project}/project-margin/options', ProjectMarginReportOptionsController::class)
            ->defaults('reportCode', 'project_margin')
            ->middleware(['project.context', 'report.project-scope', $resourceAccess])
            ->name('project-margin.options');
        Route::get('/runs/{runId}', [ReportRunController::class, 'show'])
            ->middleware($resourceAccess)
            ->name('runs.show');
        Route::get('/runs/{runId}/rows', ReportRowsController::class)
            ->middleware($resourceAccess)
            ->name('runs.rows');
        Route::post('/runs/{runId}/drill-down', ReportDrillDownController::class)
            ->middleware($resourceAccess)
            ->name('runs.drill-down');
        Route::post('/runs/{runId}/retry', [ReportRunController::class, 'retry'])
            ->middleware($resourceAccess)
            ->name('runs.retry');
        Route::post('/runs/{runId}/cancel', [ReportRunController::class, 'cancel'])
            ->middleware($resourceAccess)
            ->name('runs.cancel');
        Route::post('/runs/{runId}/exports', [ReportExportController::class, 'store'])
            ->middleware($resourceAccess)
            ->name('exports.store');
        Route::get('/exports/{exportId}', [ReportExportController::class, 'show'])
            ->middleware($resourceAccess)
            ->name('exports.show');
        Route::post('/exports/{exportId}/retry', [ReportExportController::class, 'retry'])
            ->middleware($resourceAccess)
            ->name('exports.retry');
        Route::post('/exports/{exportId}/cancel', [ReportExportController::class, 'cancel'])
            ->middleware($resourceAccess)
            ->name('exports.cancel');
        Route::post('/exports/{exportId}/download-link', [ReportExportController::class, 'downloadLink'])
            ->middleware($resourceAccess)
            ->name('exports.download-link');
    });

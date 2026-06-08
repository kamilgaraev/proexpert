<?php

declare(strict_types=1);

use App\BusinessModules\Features\Budgeting\Http\Controllers\BudgetCatalogController;
use App\BusinessModules\Features\Budgeting\Http\Controllers\BudgetImportController;
use App\BusinessModules\Features\Budgeting\Http\Controllers\BudgetLineController;
use App\BusinessModules\Features\Budgeting\Http\Controllers\BudgetMappingController;
use App\BusinessModules\Features\Budgeting\Http\Controllers\BudgetVersionController;
use App\BusinessModules\Features\Budgeting\Http\Controllers\CashGapForecastController;
use App\BusinessModules\Features\Budgeting\Http\Controllers\PlanFactReportController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/budgeting')
    ->name('admin.budgeting.')
    ->middleware(AdminRouteStack::middleware())
    ->group(function (): void {
        Route::get('/catalogs', [BudgetCatalogController::class, 'catalogs'])
            ->middleware('authorize:budgeting.budgets.view')
            ->name('catalogs');

        Route::match(['get', 'post'], '/cash-gap/forecast', [CashGapForecastController::class, 'forecast'])
            ->middleware('authorize:budgeting.cash_gap.view')
            ->name('cash_gap.forecast');

        Route::get('/plan-fact', [PlanFactReportController::class, 'index'])
            ->middleware('authorize:budgeting.plan_fact.view')
            ->name('plan_fact.index');
        Route::get('/plan-fact/drill-down', [PlanFactReportController::class, 'drillDown'])
            ->middleware('authorize:budgeting.plan_fact.view')
            ->name('plan_fact.drill_down');

        Route::get('/periods', [BudgetCatalogController::class, 'periods'])
            ->middleware('authorize:budgeting.periods.view')
            ->name('periods.index');
        Route::post('/periods', [BudgetCatalogController::class, 'storePeriod'])
            ->middleware('authorize:budgeting.periods.manage')
            ->name('periods.store');
        Route::put('/periods/{periodUuid}', [BudgetCatalogController::class, 'updatePeriod'])
            ->middleware('authorize:budgeting.periods.manage')
            ->name('periods.update');
        Route::delete('/periods/{periodUuid}', [BudgetCatalogController::class, 'destroyPeriod'])
            ->middleware('authorize:budgeting.periods.manage')
            ->name('periods.destroy');
        Route::get('/periods/{periodUuid}/closure-status', [BudgetCatalogController::class, 'periodClosureStatus'])
            ->middleware('authorize:budgeting.periods.close_status.view')
            ->name('periods.closure_status');
        Route::post('/periods/{periodUuid}/close', [BudgetCatalogController::class, 'closePeriod'])
            ->middleware('authorize:budgeting.periods.close')
            ->name('periods.close');
        Route::post('/periods/{periodUuid}/reopen-adjustment', [BudgetCatalogController::class, 'reopenPeriod'])
            ->middleware('authorize:budgeting.periods.reopen')
            ->name('periods.reopen');

        Route::get('/scenarios', [BudgetCatalogController::class, 'scenarios'])
            ->middleware('authorize:budgeting.scenarios.view')
            ->name('scenarios.index');
        Route::post('/scenarios', [BudgetCatalogController::class, 'storeScenario'])
            ->middleware('authorize:budgeting.scenarios.manage')
            ->name('scenarios.store');
        Route::put('/scenarios/{scenarioUuid}', [BudgetCatalogController::class, 'updateScenario'])
            ->middleware('authorize:budgeting.scenarios.manage')
            ->name('scenarios.update');
        Route::delete('/scenarios/{scenarioUuid}', [BudgetCatalogController::class, 'destroyScenario'])
            ->middleware('authorize:budgeting.scenarios.manage')
            ->name('scenarios.destroy');

        Route::get('/responsibility-centers', [BudgetCatalogController::class, 'responsibilityCenters'])
            ->middleware('authorize:budgeting.cfo.view')
            ->name('responsibility_centers.index');
        Route::post('/responsibility-centers', [BudgetCatalogController::class, 'storeResponsibilityCenter'])
            ->middleware('authorize:budgeting.cfo.manage')
            ->name('responsibility_centers.store');
        Route::put('/responsibility-centers/{centerUuid}', [BudgetCatalogController::class, 'updateResponsibilityCenter'])
            ->middleware('authorize:budgeting.cfo.manage')
            ->name('responsibility_centers.update');
        Route::delete('/responsibility-centers/{centerUuid}', [BudgetCatalogController::class, 'destroyResponsibilityCenter'])
            ->middleware('authorize:budgeting.cfo.manage')
            ->name('responsibility_centers.destroy');

        Route::get('/articles', [BudgetCatalogController::class, 'articles'])
            ->middleware('authorize:budgeting.articles.view')
            ->name('articles.index');
        Route::post('/articles', [BudgetCatalogController::class, 'storeArticle'])
            ->middleware('authorize:budgeting.articles.manage')
            ->name('articles.store');
        Route::put('/articles/{articleUuid}', [BudgetCatalogController::class, 'updateArticle'])
            ->middleware('authorize:budgeting.articles.manage')
            ->name('articles.update');
        Route::delete('/articles/{articleUuid}', [BudgetCatalogController::class, 'destroyArticle'])
            ->middleware('authorize:budgeting.articles.manage')
            ->name('articles.destroy');

        Route::get('/1c/mappings/articles', [BudgetMappingController::class, 'articleMappings'])
            ->middleware('authorize:budgeting.articles.map_1c')
            ->name('mappings.articles.index');
        Route::post('/1c/mappings/articles', [BudgetMappingController::class, 'storeArticleMapping'])
            ->middleware('authorize:budgeting.articles.map_1c')
            ->name('mappings.articles.store');

        Route::get('/budgets', [BudgetVersionController::class, 'index'])
            ->middleware('authorize:budgeting.budgets.view')
            ->name('budgets.index');
        Route::post('/budgets', [BudgetVersionController::class, 'store'])
            ->middleware('authorize:budgeting.budgets.create')
            ->name('budgets.store');
        Route::get('/budgets/{versionUuid}', [BudgetVersionController::class, 'show'])
            ->middleware('authorize:budgeting.budgets.view')
            ->name('budgets.show');
        Route::put('/budgets/{versionUuid}', [BudgetVersionController::class, 'update'])
            ->middleware('authorize:budgeting.budgets.edit')
            ->name('budgets.update');
        Route::delete('/budgets/{versionUuid}', [BudgetVersionController::class, 'destroy'])
            ->middleware('authorize:budgeting.budgets.archive')
            ->name('budgets.destroy');
        Route::post('/budgets/{versionUuid}/versions', [BudgetVersionController::class, 'cloneVersion'])
            ->middleware('authorize:budgeting.budgets.edit')
            ->name('budgets.versions.store');

        Route::get('/versions/{versionUuid}/lines', [BudgetLineController::class, 'index'])
            ->middleware('authorize:budgeting.budgets.view')
            ->name('versions.lines.index');
        Route::put('/versions/{versionUuid}/lines', [BudgetLineController::class, 'replace'])
            ->middleware('authorize:budgeting.budgets.edit')
            ->name('versions.lines.replace');

        Route::post('/versions/{versionUuid}/submit', [BudgetVersionController::class, 'submit'])
            ->middleware('authorize:budgeting.budgets.submit')
            ->name('versions.submit');
        Route::post('/versions/{versionUuid}/approve', [BudgetVersionController::class, 'approve'])
            ->middleware('authorize:budgeting.budgets.approve')
            ->name('versions.approve');
        Route::post('/versions/{versionUuid}/reject', [BudgetVersionController::class, 'reject'])
            ->middleware('authorize:budgeting.budgets.approve')
            ->name('versions.reject');
        Route::post('/versions/{versionUuid}/activate', [BudgetVersionController::class, 'activate'])
            ->middleware('authorize:budgeting.budgets.activate')
            ->name('versions.activate');

        Route::post('/versions/{versionUuid}/import/preview', [BudgetImportController::class, 'preview'])
            ->middleware('authorize:budgeting.import.preview')
            ->name('versions.import.preview');
        Route::post('/versions/{versionUuid}/import/commit', [BudgetImportController::class, 'commit'])
            ->middleware('authorize:budgeting.import.commit')
            ->name('versions.import.commit');
    });

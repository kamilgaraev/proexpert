<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportCatalogController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportDrillDownController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportExportController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportRowsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportRunController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSavedViewController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSubscriptionController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportWorkspacePreferencesController;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\RenderReportErrors;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/reports')
    ->name('admin.reports.')
    ->middleware(AdminRouteStack::middleware(['module.access:reports', RenderReportErrors::class]))
    ->group(function (): void {
        Route::get('/catalog', ReportCatalogController::class)
            ->middleware('authorize:reports.view')
            ->name('catalog');
        Route::get('/workspace', [ReportWorkspacePreferencesController::class, 'show'])
            ->middleware('authorize:reports.view')
            ->name('workspace.show');
        Route::post('/workspace/recent/{reportCode}', [ReportWorkspacePreferencesController::class, 'recordRecent'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('workspace.recent.store');
        Route::put('/workspace/favourites', [ReportWorkspacePreferencesController::class, 'setFavourites'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('workspace.favourites.update');
        Route::patch('/workspace/preferences', [ReportWorkspacePreferencesController::class, 'updatePreferences'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('workspace.preferences.update');
        Route::get('/saved-views', [ReportSavedViewController::class, 'index'])
            ->middleware('authorize:reports.view')->name('saved-views.index');
        Route::post('/saved-views', [ReportSavedViewController::class, 'store'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])->name('saved-views.store');
        Route::get('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'show'])
            ->middleware('authorize:reports.view')->name('saved-views.show');
        Route::patch('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'update'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])->name('saved-views.update');
        Route::delete('/saved-views/{savedViewId}', [ReportSavedViewController::class, 'destroy'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])->name('saved-views.destroy');
        Route::post('/saved-views/{savedViewId}/set-default', [ReportSavedViewController::class, 'setDefault'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])->name('saved-views.set-default');
        Route::get('/subscriptions', [ReportSubscriptionController::class, 'index'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('subscriptions.index');
        Route::post('/subscriptions', [ReportSubscriptionController::class, 'store'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('subscriptions.store');
        Route::patch('/subscriptions/{subscriptionId}', [ReportSubscriptionController::class, 'update'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('subscriptions.update');
        Route::delete('/subscriptions/{subscriptionId}', [ReportSubscriptionController::class, 'destroy'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('subscriptions.destroy');
        Route::post('/subscriptions/{subscriptionId}/pause', [ReportSubscriptionController::class, 'pause'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('subscriptions.pause');
        Route::post('/subscriptions/{subscriptionId}/resume', [ReportSubscriptionController::class, 'resume'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('subscriptions.resume');
        Route::post('/subscriptions/{subscriptionId}/run-now', [ReportSubscriptionController::class, 'runNow'])
            ->middleware(['authorize:reports.view', 'authorize:reports.manage'])
            ->name('subscriptions.run-now');
        Route::post('/{reportCode}/runs', [ReportRunController::class, 'store'])
            ->middleware(['authorize:reports.view', 'authorize:reports.run'])
            ->name('runs.store');
        Route::get('/runs/{runId}', [ReportRunController::class, 'show'])
            ->middleware('authorize:reports.view')
            ->name('runs.show');
        Route::get('/runs/{runId}/rows', ReportRowsController::class)
            ->middleware('authorize:reports.view')
            ->name('runs.rows');
        Route::post('/runs/{runId}/drill-down', ReportDrillDownController::class)
            ->middleware('authorize:reports.view')
            ->name('runs.drill-down');
        Route::post('/runs/{runId}/retry', [ReportRunController::class, 'retry'])
            ->middleware(['authorize:reports.view', 'authorize:reports.run'])
            ->name('runs.retry');
        Route::post('/runs/{runId}/cancel', [ReportRunController::class, 'cancel'])
            ->middleware(['authorize:reports.view', 'authorize:reports.run'])
            ->name('runs.cancel');
        Route::post('/runs/{runId}/exports', [ReportExportController::class, 'store'])
            ->middleware(['authorize:reports.view', 'authorize:reports.export'])
            ->name('exports.store');
        Route::get('/exports/{exportId}', [ReportExportController::class, 'show'])
            ->middleware('authorize:reports.view')
            ->name('exports.show');
        Route::post('/exports/{exportId}/retry', [ReportExportController::class, 'retry'])
            ->middleware(['authorize:reports.view', 'authorize:reports.export'])
            ->name('exports.retry');
        Route::post('/exports/{exportId}/cancel', [ReportExportController::class, 'cancel'])
            ->middleware(['authorize:reports.view', 'authorize:reports.export'])
            ->name('exports.cancel');
        Route::post('/exports/{exportId}/download-link', [ReportExportController::class, 'downloadLink'])
            ->middleware(['authorize:reports.view', 'authorize:reports.export', 'authorize:reports.download'])
            ->name('exports.download-link');
    });

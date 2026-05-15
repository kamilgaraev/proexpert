<?php

declare(strict_types=1);

use App\BusinessModules\Features\MachineryOperations\Http\Controllers\MachineryOperationsController;
use App\BusinessModules\Features\MachineryOperations\Http\Controllers\Mobile\MachineryOperationsController as MobileMachineryOperationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/machinery-operations')
    ->name('admin.machinery_operations.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'machinery-operations.active'])
    ->group(function (): void {
        Route::get('/assets', [MachineryOperationsController::class, 'assets'])
            ->middleware('authorize:machinery-operations.view')
            ->name('assets.index');
        Route::post('/assets', [MachineryOperationsController::class, 'storeAsset'])
            ->middleware('authorize:machinery-operations.create')
            ->name('assets.store');
        Route::post('/assets/{id}/assign', [MachineryOperationsController::class, 'assignAsset'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.requests.approve')
            ->name('assets.assign');
        Route::post('/assets/{id}/start-operation', [MachineryOperationsController::class, 'startOperation'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.shifts.create')
            ->name('assets.start_operation');
        Route::post('/assets/{id}/maintenance', [MachineryOperationsController::class, 'setMaintenance'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.downtime.manage')
            ->name('assets.maintenance');
        Route::post('/assets/{id}/unavailable', [MachineryOperationsController::class, 'setUnavailable'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.downtime.manage')
            ->name('assets.unavailable');
        Route::post('/assets/{id}/return-available', [MachineryOperationsController::class, 'returnAvailable'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.edit')
            ->name('assets.return_available');
        Route::post('/assets/{id}/archive', [MachineryOperationsController::class, 'archiveAsset'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.delete')
            ->name('assets.archive');

        Route::get('/shift-reports', [MachineryOperationsController::class, 'shifts'])
            ->middleware('authorize:machinery-operations.view')
            ->name('shift_reports.index');
        Route::post('/shift-reports', [MachineryOperationsController::class, 'storeShift'])
            ->middleware('authorize:machinery-operations.shifts.create')
            ->name('shift_reports.store');
        Route::post('/shift-reports/{id}/submit', [MachineryOperationsController::class, 'submitShift'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.shifts.create')
            ->name('shift_reports.submit');
        Route::post('/shift-reports/{id}/approve', [MachineryOperationsController::class, 'approveShift'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.shifts.approve')
            ->name('shift_reports.approve');
        Route::post('/shift-reports/{id}/reject', [MachineryOperationsController::class, 'rejectShift'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.shifts.approve')
            ->name('shift_reports.reject');

        Route::post('/downtimes', [MachineryOperationsController::class, 'storeDowntime'])
            ->middleware('authorize:machinery-operations.downtime.manage')
            ->name('downtimes.store');
        Route::post('/fuel-issues', [MachineryOperationsController::class, 'storeFuelIssue'])
            ->middleware('authorize:machinery-operations.fuel.manage')
            ->name('fuel_issues.store');

        Route::get('/maintenance-orders', [MachineryOperationsController::class, 'maintenanceOrders'])
            ->middleware('authorize:machinery-operations.view')
            ->name('maintenance_orders.index');
        Route::post('/maintenance-orders', [MachineryOperationsController::class, 'storeMaintenanceOrder'])
            ->middleware('authorize:machinery-operations.downtime.manage')
            ->name('maintenance_orders.store');
        Route::post('/maintenance-orders/{id}/complete', [MachineryOperationsController::class, 'completeMaintenanceOrder'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.downtime.manage')
            ->name('maintenance_orders.complete');

        Route::get('/reports', [MachineryOperationsController::class, 'reports'])
            ->middleware('authorize:machinery-operations.view')
            ->name('reports.index');
    });

Route::prefix('api/v1/mobile/machinery-operations')
    ->name('mobile.machinery_operations.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'machinery-operations.active'])
    ->group(function (): void {
        Route::get('/assets', [MobileMachineryOperationsController::class, 'assets'])
            ->middleware('authorize:machinery-operations.view')
            ->name('assets.index');
        Route::get('/shift-reports', [MobileMachineryOperationsController::class, 'shifts'])
            ->middleware('authorize:machinery-operations.view')
            ->name('shift_reports.index');
        Route::post('/shift-reports', [MobileMachineryOperationsController::class, 'storeShift'])
            ->middleware('authorize:machinery-operations.shifts.create')
            ->name('shift_reports.store');
        Route::post('/shift-reports/{id}/submit', [MobileMachineryOperationsController::class, 'submitShift'])
            ->whereNumber('id')
            ->middleware('authorize:machinery-operations.shifts.create')
            ->name('shift_reports.submit');
        Route::post('/downtimes', [MobileMachineryOperationsController::class, 'storeDowntime'])
            ->middleware('authorize:machinery-operations.downtime.manage')
            ->name('downtimes.store');
        Route::post('/fuel-issues', [MobileMachineryOperationsController::class, 'storeFuelIssue'])
            ->middleware('authorize:machinery-operations.fuel.manage')
            ->name('fuel_issues.store');
    });

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$adminController = \App\BusinessModules\Features\ProductionLabor\Http\Controllers\ProductionLaborController::class;
$mobileController = \App\BusinessModules\Features\ProductionLabor\Http\Controllers\Mobile\ProductionLaborController::class;

Route::prefix('api/v1/admin/production-labor')
    ->name('admin.production_labor.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'production-labor.active'])
    ->group(function () use ($adminController): void {
        Route::get('/work-orders', [$adminController, 'workOrders'])->middleware('authorize:production-labor.view');
        Route::post('/work-orders', [$adminController, 'storeWorkOrder'])->middleware('authorize:production-labor.work-orders.create');
        Route::post('/work-orders/{id}/issue', [$adminController, 'issueWorkOrder'])->whereNumber('id')->middleware('authorize:production-labor.work-orders.approve');
        Route::post('/work-orders/{id}/start', [$adminController, 'startWorkOrder'])->whereNumber('id')->middleware('authorize:production-labor.output.record');
        Route::post('/work-orders/{id}/submit', [$adminController, 'submitWorkOrder'])->whereNumber('id')->middleware('authorize:production-labor.output.record');
        Route::post('/work-orders/{id}/accept', [$adminController, 'acceptWorkOrder'])->whereNumber('id')->middleware('authorize:production-labor.output.approve');
        Route::post('/work-orders/{id}/return', [$adminController, 'returnWorkOrder'])->whereNumber('id')->middleware('authorize:production-labor.output.approve');
        Route::post('/work-orders/{id}/close', [$adminController, 'closeWorkOrder'])->whereNumber('id')->middleware('authorize:production-labor.work-orders.approve');
        Route::post('/work-orders/{id}/cancel', [$adminController, 'cancelWorkOrder'])->whereNumber('id')->middleware('authorize:production-labor.work-orders.approve');
        Route::get('/output-entries', [$adminController, 'outputEntries'])->middleware('authorize:production-labor.view');
        Route::post('/output-entries', [$adminController, 'storeOutput'])->middleware('authorize:production-labor.output.record');
        Route::get('/timesheets', [$adminController, 'timesheets'])->middleware('authorize:production-labor.view');
        Route::post('/timesheets', [$adminController, 'storeTimesheet'])->middleware('authorize:production-labor.output.record');
        Route::get('/payroll-accruals', [$adminController, 'payrollAccruals'])->middleware('authorize:production-labor.view');
        Route::post('/payroll-accruals/prepare', [$adminController, 'preparePayroll'])->middleware('authorize:production-labor.payroll.prepare');
        Route::get('/reports', [$adminController, 'reports'])->middleware('authorize:production-labor.view');
    });

Route::prefix('api/v1/mobile/production-labor')
    ->name('mobile.production_labor.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'production-labor.active'])
    ->group(function () use ($mobileController): void {
        Route::get('/work-orders', [$mobileController, 'workOrders'])->middleware('authorize:production-labor.view');
        Route::post('/output-entries', [$mobileController, 'storeOutput'])->middleware('authorize:production-labor.output.record');
        Route::post('/timesheets', [$mobileController, 'storeTimesheet'])->middleware('authorize:production-labor.output.record');
    });

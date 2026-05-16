<?php

declare(strict_types=1);

use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceEmployeeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/workforce')
    ->name('admin.workforce.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context'])
    ->group(function (): void {
        Route::get('/employees', [WorkforceEmployeeController::class, 'index'])
            ->middleware('authorize:workforce.view');
        Route::post('/employees', [WorkforceEmployeeController::class, 'store'])
            ->middleware('authorize:workforce.employees.basic');
        Route::get('/employees/{employeeId}', [WorkforceEmployeeController::class, 'show'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.view');
        Route::put('/employees/{employeeId}', [WorkforceEmployeeController::class, 'update'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.employees.basic');
        Route::post('/employees/{employeeId}/dismiss', [WorkforceEmployeeController::class, 'dismiss'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.employees.basic');
    });

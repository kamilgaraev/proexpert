<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\FieldAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->prefix('field-admin')
    ->group(function (): void {
        Route::prefix('team/projects/{project}/participants')
            ->whereNumber('project')
            ->name('field_admin.team.')
            ->group(function (): void {
                Route::get('/', [FieldAdminController::class, 'projectTeam'])
                    ->middleware('authorize:projects.view,project,project')
                    ->name('participants.index');
                Route::get('/available-users', [FieldAdminController::class, 'availableProjectUsers'])
                    ->middleware('authorize:projects.participants.assign,project,project')
                    ->name('participants.available');
                Route::put('/{user}', [FieldAdminController::class, 'bindProjectUser'])
                    ->whereNumber('user')
                    ->middleware('authorize:projects.participants.assign,project,project')
                    ->name('participants.bind');
            });

        Route::prefix('personnel')
            ->name('field_admin.personnel.')
            ->group(function (): void {
                Route::get('/employees', [FieldAdminController::class, 'employees'])
                    ->middleware('mobile.project-authorize:workforce.view')
                    ->name('employees.index');
                Route::get('/employees/{employee}', [FieldAdminController::class, 'employee'])
                    ->whereNumber('employee')
                    ->middleware('mobile.project-authorize:workforce.view')
                    ->name('employees.show');
                Route::get('/absences', [FieldAdminController::class, 'absences'])
                    ->middleware('mobile.project-authorize:workforce.view')
                    ->name('absences.index');
                Route::get('/orders', [FieldAdminController::class, 'orders'])
                    ->middleware('mobile.project-authorize:workforce.view')
                    ->name('orders.index');
                Route::get('/attendance', [FieldAdminController::class, 'attendance'])
                    ->middleware('mobile.project-authorize:workforce.view')
                    ->name('attendance.index');
                Route::get('/calendar', [FieldAdminController::class, 'calendar'])
                    ->middleware('mobile.project-authorize:workforce.view')
                    ->name('calendar');
            });
    });

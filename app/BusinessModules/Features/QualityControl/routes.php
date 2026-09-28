<?php

declare(strict_types=1);

use App\BusinessModules\Features\QualityControl\Http\Controllers\Customer\QualityDefectController as CustomerQualityDefectController;
use App\BusinessModules\Features\QualityControl\Http\Controllers\Mobile\QualityDefectController as MobileQualityDefectController;
use App\BusinessModules\Features\QualityControl\Http\Controllers\QualityDefectController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/quality-control')
    ->name('admin.quality_control.')
    ->middleware(AdminRouteStack::middleware(['quality-control.active']))
    ->group(function (): void {
        Route::prefix('defects')->name('defects.')->group(function (): void {
            Route::get('/', [QualityDefectController::class, 'index'])
                ->middleware('authorize:quality-control.view')
                ->name('index');
            Route::post('/', [QualityDefectController::class, 'store'])
                ->middleware('authorize:quality-control.defects.create')
                ->name('store');
            Route::get('/{id}', [QualityDefectController::class, 'show'])
                ->middleware('authorize:quality-control.view')
                ->name('show');
            Route::post('/{id}/assign', [QualityDefectController::class, 'assign'])
                ->middleware('authorize:quality-control.defects.assign')
                ->name('assign');
            Route::post('/{id}/start', [QualityDefectController::class, 'start'])
                ->middleware('authorize:quality-control.defects.resolve')
                ->name('start');
            Route::post('/{id}/resolve', [QualityDefectController::class, 'resolve'])
                ->middleware('authorize:quality-control.defects.resolve')
                ->name('resolve');
            Route::post('/{id}/verify', [QualityDefectController::class, 'verify'])
                ->middleware('authorize:quality-control.defects.verify')
                ->name('verify');
            Route::post('/{id}/reject', [QualityDefectController::class, 'reject'])
                ->middleware('authorize:quality-control.defects.reject')
                ->name('reject');
            Route::post('/{id}/cancel', [QualityDefectController::class, 'cancel'])
                ->middleware('authorize:quality-control.defects.reject')
                ->name('cancel');
        });
    });

Route::prefix('api/v1/mobile/quality-control')
    ->name('mobile.quality_control.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'quality-control.active'])
    ->group(function (): void {
        Route::prefix('defects')->name('defects.')->group(function (): void {
            Route::get('/', [MobileQualityDefectController::class, 'index'])
                ->middleware('mobile.project-authorize:quality-control.view,project_id')
                ->name('index');
            Route::post('/', [MobileQualityDefectController::class, 'store'])
                ->middleware('mobile.project-authorize:quality-control.defects.create,project_id')
                ->name('store');
            Route::get('/{id}', [MobileQualityDefectController::class, 'show'])
                ->middleware('mobile.project-authorize:quality-control.view,quality_defect,id')
                ->name('show');
            Route::get('/{id}/assignees', [MobileQualityDefectController::class, 'assignees'])
                ->middleware('mobile.project-authorize:quality-control.view,quality_defect,id')
                ->name('assignees');
            Route::post('/{id}/assign', [MobileQualityDefectController::class, 'assign'])
                ->middleware('mobile.project-authorize:quality-control.defects.assign,quality_defect,id')
                ->name('assign');
            Route::post('/{id}/start', [MobileQualityDefectController::class, 'start'])
                ->middleware('mobile.project-authorize:quality-control.defects.resolve,quality_defect,id')
                ->name('start');
            Route::post('/{id}/resolve', [MobileQualityDefectController::class, 'resolve'])
                ->middleware('mobile.project-authorize:quality-control.defects.resolve,quality_defect,id')
                ->name('resolve');
            Route::post('/{id}/verify', [MobileQualityDefectController::class, 'verify'])
                ->middleware('mobile.project-authorize:quality-control.defects.verify,quality_defect,id')
                ->name('verify');
            Route::post('/{id}/reject', [MobileQualityDefectController::class, 'reject'])
                ->middleware('mobile.project-authorize:quality-control.defects.reject,quality_defect,id')
                ->name('reject');
        });
    });

Route::prefix('api/v1/customer/quality-control')
    ->name('customer.quality_control.')
    ->middleware(['auth:api_landing', 'auth.jwt:api_landing', 'verified', 'organization.context', 'quality-control.active'])
    ->group(function (): void {
        Route::prefix('defects')->name('defects.')->group(function (): void {
            Route::get('/', [CustomerQualityDefectController::class, 'index'])
                ->middleware('authorize:quality-control.view')
                ->name('index');
            Route::get('/{id}', [CustomerQualityDefectController::class, 'show'])
                ->middleware('authorize:quality-control.view')
                ->name('show');
        });
    });

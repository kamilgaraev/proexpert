<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignManagementController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management')
    ->name('admin.design_management.')
    ->middleware(AdminRouteStack::middleware(['design-management.active']))
    ->group(function (): void {
        Route::get('/packages', [DesignManagementController::class, 'packages'])
            ->middleware('authorize:design-management.view')
            ->name('packages.index');
        Route::post('/packages', [DesignManagementController::class, 'storePackage'])
            ->middleware('authorize:design-management.create')
            ->name('packages.store');
        Route::get('/packages/{packageId}', [DesignManagementController::class, 'showPackage'])
            ->middleware('authorize:design-management.view')
            ->name('packages.show');
        Route::post('/packages/{packageId}/models', [DesignManagementController::class, 'uploadModel'])
            ->middleware('authorize:design-management.models.upload')
            ->name('models.upload');
        Route::post('/model-versions/{versionId}/derivatives', [DesignManagementController::class, 'storeDerivative'])
            ->middleware('authorize:design-management.models.prepare_viewer')
            ->name('model_versions.derivatives.store');
        Route::get('/model-versions/{versionId}/viewer', [DesignManagementController::class, 'viewerPayload'])
            ->middleware('authorize:design-management.models.view')
            ->name('model_versions.viewer');
        Route::post('/model-versions/{versionId}/mark-current', [DesignManagementController::class, 'markCurrent'])
            ->middleware('authorize:design-management.edit')
            ->name('model_versions.mark_current');
    });

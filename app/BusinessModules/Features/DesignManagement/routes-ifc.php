<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignIfcElementController;
use App\BusinessModules\Features\DesignManagement\Http\Controllers\BimConstructionProgressController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management')
    ->name('admin.design_management.')
    ->middleware(AdminRouteStack::middleware(['design-management.active']))
    ->group(function (): void {
        Route::get('/model-versions/{versionId}/elements', [DesignIfcElementController::class, 'index'])
            ->middleware('authorize:design-management.models.view')
            ->name('model_versions.elements.index');
        Route::get('/model-versions/{versionId}/elements/{elementId}/properties', [DesignIfcElementController::class, 'show'])
            ->middleware('authorize:design-management.models.view')
            ->name('model_versions.elements.properties.show');
        Route::get('/model-versions/{versionId}/construction-progress', [BimConstructionProgressController::class, 'index'])
            ->middleware('authorize:design-management.models.view')->name('model_versions.construction_progress.index');
        Route::post('/model-versions/{versionId}/construction-progress', [BimConstructionProgressController::class, 'store'])
            ->middleware('authorize:design-management.models.edit')->name('model_versions.construction_progress.store');
        Route::patch('/model-versions/{versionId}/construction-progress/{groupId}', [BimConstructionProgressController::class, 'update'])
            ->middleware('authorize:design-management.models.edit')->name('model_versions.construction_progress.update');
        Route::delete('/model-versions/{versionId}/construction-progress/{groupId}', [BimConstructionProgressController::class, 'destroy'])
            ->middleware('authorize:design-management.models.edit')->name('model_versions.construction_progress.destroy');
    });

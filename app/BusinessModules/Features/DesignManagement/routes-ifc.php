<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignIfcElementController;
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
    });

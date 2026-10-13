<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignDrawingStrokeController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management/document-versions/{versionId}/drawing-strokes')
    ->whereNumber('versionId')->name('admin.design_management.drawing_strokes.')
    ->middleware(AdminRouteStack::middleware(['design-management.active']))
    ->group(function (): void {
        Route::get('/', [DesignDrawingStrokeController::class, 'index'])
            ->middleware('authorize:design-management.documents.view')->name('index');
        Route::post('/', [DesignDrawingStrokeController::class, 'store'])
            ->middleware('authorize:design-management.review')->name('store');
        Route::delete('/{clientId}', [DesignDrawingStrokeController::class, 'destroy'])
            ->whereUuid('clientId')->middleware('authorize:design-management.review')->name('destroy');
    });

<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignCompositionController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management/composition')->name('admin.design_management.composition.')->middleware(AdminRouteStack::middleware(['design-management.active']))->group(function (): void {
    Route::post('/preview', [DesignCompositionController::class, 'preview'])->middleware('authorize:design-management.composition.edit')->name('preview');
    Route::get('/packages/{packageId}/composition', [DesignCompositionController::class, 'show'])->middleware('authorize:design-management.view')->name('show');
    Route::post('/packages/{packageId}/revisions', [DesignCompositionController::class, 'store'])->middleware('authorize:design-management.composition.edit')->name('revisions.store');
    Route::post('/revisions/{revisionId}/approve', [DesignCompositionController::class, 'approve'])->middleware('authorize:design-management.composition.approve')->name('revisions.approve');
    Route::post('/revisions/{revisionId}/needs-review', [DesignCompositionController::class, 'needsReview'])->middleware('authorize:design-management.composition.edit')->name('revisions.needs_review');
    Route::post('/revisions/{revisionId}/exclusions', [DesignCompositionController::class, 'exclude'])->middleware('authorize:design-management.composition.edit')->name('revisions.exclusions.store');
});

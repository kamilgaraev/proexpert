<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignSourceLinkController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management')->name('admin.design_management.')->middleware(AdminRouteStack::middleware(['design-management.active']))->group(function (): void {
    Route::get('/document-versions/{versionId}/source-links', [DesignSourceLinkController::class, 'sourceLinks'])->middleware('authorize:design-management.view')->name('source_links.source');
    Route::get('/source-links/targets', [DesignSourceLinkController::class, 'searchTargets'])->middleware('authorize:design-management.view')->name('source_links.target_search');
    Route::post('/source-links', [DesignSourceLinkController::class, 'store'])->middleware('authorize:design-management.edit')->name('source_links.store');
    Route::get('/source-links/{linkId}/context', [DesignSourceLinkController::class, 'sourceContext'])->middleware('authorize:design-management.view')->name('source_links.context');
    Route::delete('/source-links/{linkId}', [DesignSourceLinkController::class, 'destroy'])->middleware('authorize:design-management.edit')->name('source_links.destroy');
    Route::get('/document-versions/{versionId}/impact-reviews', [DesignSourceLinkController::class, 'reviews'])->middleware('authorize:design-management.view')->name('impact_reviews.index');
    Route::post('/impact-reviews/{reviewId}/decision', [DesignSourceLinkController::class, 'decide'])->middleware('authorize:design-management.edit')->name('impact_reviews.decide');
    Route::get('/impact-reviews/{reviewId}/replacement-sheets', [DesignSourceLinkController::class, 'replacementSheets'])->middleware('authorize:design-management.view')->name('impact_reviews.replacement_sheets');
});

Route::prefix('api/v1/admin/design-management')->name('admin.design_management.')->middleware(AdminRouteStack::middleware())->group(function (): void {
    Route::get('/source-links/targets/{targetType}/{targetId}', [DesignSourceLinkController::class, 'targetLinks'])->name('source_links.target');
});

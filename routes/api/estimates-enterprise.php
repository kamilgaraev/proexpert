<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Estimates\NormativeRateController;
use App\Http\Controllers\Api\Estimates\EstimateLibraryController;
use App\Http\Controllers\Api\Estimates\EstimateAuditController;
use App\Http\Controllers\Api\Estimates\NormativeImportController;
use App\Http\Controllers\Api\Estimates\OfficialFormsExportController;
use App\Http\Controllers\Api\Estimates\EstimateConstructorController;

Route::prefix('estimates')->group(function () {
    
    Route::prefix('normative-rates')->group(function () {
        Route::get('/', [NormativeRateController::class, 'index']);
        Route::get('/search', [NormativeRateController::class, 'search']);
        Route::get('/collections', [NormativeRateController::class, 'collections']);
        Route::get('/collections/{collectionId}/sections', [NormativeRateController::class, 'sections']);
        Route::get('/most-used', [NormativeRateController::class, 'mostUsed']);
        Route::get('/{id}', [NormativeRateController::class, 'show']);
        Route::get('/{id}/resources', [NormativeRateController::class, 'resources']);
        Route::get('/{id}/similar', [NormativeRateController::class, 'similar']);
    });

    Route::prefix('libraries')->group(function () {
        Route::get('/', [EstimateLibraryController::class, 'index']);
        Route::post('/', [EstimateLibraryController::class, 'store']);
        Route::get('/{id}', [EstimateLibraryController::class, 'show']);
        Route::put('/{id}', [EstimateLibraryController::class, 'update']);
        Route::delete('/{id}', [EstimateLibraryController::class, 'destroy']);
        
        Route::post('/{id}/duplicate', [EstimateLibraryController::class, 'duplicate']);
        Route::post('/{id}/share', [EstimateLibraryController::class, 'share']);
        
        Route::get('/{libraryId}/items', [EstimateLibraryController::class, 'items']);
        Route::post('/{libraryId}/items', [EstimateLibraryController::class, 'storeItem']);
        
        Route::post('/items/{itemId}/apply', [EstimateLibraryController::class, 'applyItem']);
        Route::get('/items/{itemId}/statistics', [EstimateLibraryController::class, 'itemStatistics']);
    });

    Route::prefix('audit')->group(function () {
        Route::get('/{estimateId}/history', [EstimateAuditController::class, 'history']);
        Route::get('/{estimateId}/snapshots', [EstimateAuditController::class, 'snapshots']);
        Route::post('/{estimateId}/snapshots', [EstimateAuditController::class, 'createSnapshot']);
        Route::post('/{estimateId}/snapshots/{snapshotId}/restore', [EstimateAuditController::class, 'restore']);
        
        Route::post('/compare', [EstimateAuditController::class, 'compare']);
        Route::post('/compare-snapshots', [EstimateAuditController::class, 'compareSnapshots']);
    });

    Route::prefix('import')->group(function () {
        Route::post('/upload', [NormativeImportController::class, 'upload']);
        Route::get('/status/{importLogId}', [NormativeImportController::class, 'status']);
        Route::get('/history', [NormativeImportController::class, 'history']);
        Route::post('/retry/{importLogId}', [NormativeImportController::class, 'retry']);
    });

    Route::prefix('export')->group(function () {
        Route::get('/ks2/{actId}', [OfficialFormsExportController::class, 'exportKS2']);
        Route::get('/ks3/{actId}', [OfficialFormsExportController::class, 'exportKS3']);
        Route::get('/ks-both/{actId}', [OfficialFormsExportController::class, 'exportBothForms']);
    });

    Route::prefix('constructor')->group(function () {
        Route::post('/{estimateId}/add-from-normatives', [EstimateConstructorController::class, 'addItemsFromNormatives']);
        Route::post('/{estimateId}/add-from-catalog', [EstimateConstructorController::class, 'addItemsFromCatalog']);
        Route::post('/{estimateId}/bulk-update', [EstimateConstructorController::class, 'bulkUpdate']);
        Route::post('/{estimateId}/bulk-delete', [EstimateConstructorController::class, 'bulkDelete']);
        Route::post('/{estimateId}/reorder', [EstimateConstructorController::class, 'reorderItems']);
        Route::post('/{estimateId}/move-to-section', [EstimateConstructorController::class, 'moveItemsToSection']);
        Route::post('/{estimateId}/copy-items', [EstimateConstructorController::class, 'copyItems']);
        Route::post('/{estimateId}/apply-coefficients', [EstimateConstructorController::class, 'applyCoefficientsToItems']);
        Route::post('/{estimateId}/apply-indices', [EstimateConstructorController::class, 'applyIndicesToItems']);
        Route::post('/{estimateId}/recalculate', [EstimateConstructorController::class, 'recalculateEstimate']);
    });
});


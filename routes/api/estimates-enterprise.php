<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Estimates\NormativeRateController;
use App\Http\Controllers\Api\Estimates\EstimateLibraryController;
use App\Http\Controllers\Api\Estimates\EstimateAuditController;

Route::prefix('estimates')->middleware(['auth:sanctum'])->group(function () {
    
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
});

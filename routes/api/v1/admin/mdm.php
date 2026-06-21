<?php

declare(strict_types=1);

use App\BusinessModules\Core\Mdm\Http\Controllers\MdmController;
use Illuminate\Support\Facades\Route;

Route::prefix('mdm')
    ->name('mdm.')
    ->group(function (): void {
        Route::middleware('authorize:mdm.view')->group(function (): void {
            Route::get('/entities', [MdmController::class, 'entities'])->name('entities');
            Route::get('/dashboard', [MdmController::class, 'dashboard'])->name('dashboard');
            Route::get('/records', [MdmController::class, 'records'])->name('records');
            Route::get('/records/{record}', [MdmController::class, 'record'])->name('records.show');
            Route::get('/duplicates', [MdmController::class, 'duplicates'])->name('duplicates');
            Route::get('/relationships', [MdmController::class, 'relationships'])->name('relationships');
            Route::get('/history', [MdmController::class, 'history'])->name('history');
            Route::get('/change-requests', [MdmController::class, 'changeRequests'])->middleware('authorize:mdm.change_requests.view')->name('change_requests');
            Route::get('/change-requests/{changeRequest}', [MdmController::class, 'changeRequest'])->middleware('authorize:mdm.change_requests.view')->name('change_requests.show');
            Route::get('/change-requests/{changeRequest}/timeline', [MdmController::class, 'changeRequestTimeline'])->middleware('authorize:mdm.change_requests.view')->name('change_requests.timeline');
            Route::get('/change-requests/{changeRequest}/impact', [MdmController::class, 'changeRequestImpact'])->middleware('authorize:mdm.impact.view')->name('change_requests.impact');
            Route::get('/quality-policies', [MdmController::class, 'qualityPolicies'])->name('quality_policies');
        });

        Route::post('/sync', [MdmController::class, 'sync'])->middleware('authorize:mdm.manage')->name('sync');
        Route::post('/duplicates/scan', [MdmController::class, 'scanDuplicates'])->middleware('authorize:mdm.manage')->name('duplicates.scan');
        Route::post('/duplicates/{group}/resolve', [MdmController::class, 'resolveDuplicate'])->middleware('authorize:mdm.duplicates.resolve')->name('duplicates.resolve');
        Route::post('/duplicates/{group}/merge-plan', [MdmController::class, 'mergePlan'])->middleware('authorize:mdm.merge.apply')->name('duplicates.merge_plan');
        Route::post('/duplicates/{group}/merge-apply', [MdmController::class, 'mergeApply'])->middleware('authorize:mdm.merge.apply')->name('duplicates.merge_apply');
        Route::post('/records/{entityType}/{entityId}/archive', [MdmController::class, 'archive'])->middleware('authorize:mdm.archive')->name('records.archive');
        Route::post('/records/{record}/owner', [MdmController::class, 'assignOwner'])->middleware('authorize:mdm.owners.assign')->name('records.owner');
        Route::post('/relationships/sync', [MdmController::class, 'syncRelationships'])->middleware('authorize:mdm.manage')->name('relationships.sync');
        Route::post('/imports/preview', [MdmController::class, 'importPreview'])->middleware('authorize:mdm.import.preview')->name('imports.preview');
        Route::post('/imports/apply', [MdmController::class, 'importApply'])->middleware('authorize:mdm.import.apply')->name('imports.apply');
        Route::post('/imports/file/preview', [MdmController::class, 'fileImportPreview'])->middleware('authorize:mdm.import.preview')->name('imports.file.preview');
        Route::post('/imports/file/apply', [MdmController::class, 'fileImportApply'])->middleware('authorize:mdm.import.apply')->name('imports.file.apply');
        Route::post('/change-requests/preview', [MdmController::class, 'previewChangeRequest'])->middleware('authorize:mdm.change_requests.create')->name('change_requests.preview');
        Route::post('/change-requests', [MdmController::class, 'submitChangeRequest'])->middleware('authorize:mdm.change_requests.create')->name('change_requests.create');
        Route::patch('/change-requests/{changeRequest}', [MdmController::class, 'updateChangeRequest'])->middleware('authorize:mdm.change_requests.create')->name('change_requests.update');
        Route::post('/change-requests/{changeRequest}/submit', [MdmController::class, 'submitDraftChangeRequest'])->middleware('authorize:mdm.change_requests.submit')->name('change_requests.submit');
        Route::post('/change-requests/{changeRequest}/start-review', [MdmController::class, 'startReviewChangeRequest'])->middleware('authorize:mdm.change_requests.review')->name('change_requests.start_review');
        Route::post('/change-requests/{changeRequest}/approve', [MdmController::class, 'approveChangeRequest'])->middleware('authorize:mdm.change_requests.approve')->name('change_requests.approve');
        Route::post('/change-requests/{changeRequest}/reject', [MdmController::class, 'rejectChangeRequest'])->middleware('authorize:mdm.change_requests.reject')->name('change_requests.reject');
        Route::post('/change-requests/{changeRequest}/apply', [MdmController::class, 'applyChangeRequest'])->middleware('authorize:mdm.change_requests.apply')->name('change_requests.apply');
        Route::post('/change-requests/{changeRequest}/cancel', [MdmController::class, 'cancelChangeRequest'])->middleware('authorize:mdm.change_requests.cancel')->name('change_requests.cancel');
        Route::post('/change-requests/{changeRequest}/review', [MdmController::class, 'reviewChangeRequest'])->middleware('authorize:mdm.change_requests.review')->name('change_requests.review');
        Route::put('/quality-policies/{entityType}', [MdmController::class, 'updateQualityPolicy'])->middleware('authorize:mdm.manage')->name('quality_policies.update');
    });

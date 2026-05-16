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
            Route::get('/duplicates', [MdmController::class, 'duplicates'])->name('duplicates');
            Route::get('/relationships', [MdmController::class, 'relationships'])->name('relationships');
            Route::get('/history', [MdmController::class, 'history'])->name('history');
            Route::get('/change-requests', [MdmController::class, 'changeRequests'])->name('change_requests');
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
        Route::post('/change-requests', [MdmController::class, 'submitChangeRequest'])->middleware('authorize:mdm.manage')->name('change_requests.submit');
        Route::post('/change-requests/{changeRequest}/review', [MdmController::class, 'reviewChangeRequest'])->middleware('authorize:mdm.change_requests.review')->name('change_requests.review');
        Route::put('/quality-policies/{entityType}', [MdmController::class, 'updateQualityPolicy'])->middleware('authorize:mdm.manage')->name('quality_policies.update');
    });

<?php

declare(strict_types=1);

use App\BusinessModules\Core\Mdm\Http\Controllers\MdmController;
use Illuminate\Support\Facades\Route;

Route::prefix('mdm')
    ->name('mdm.')
    ->middleware('authorize:admin.catalogs.manage')
    ->group(function (): void {
        Route::get('/entities', [MdmController::class, 'entities'])->name('entities');
        Route::get('/dashboard', [MdmController::class, 'dashboard'])->name('dashboard');
        Route::get('/records', [MdmController::class, 'records'])->name('records');
        Route::post('/sync', [MdmController::class, 'sync'])->name('sync');
        Route::get('/duplicates', [MdmController::class, 'duplicates'])->name('duplicates');
        Route::post('/duplicates/scan', [MdmController::class, 'scanDuplicates'])->name('duplicates.scan');
        Route::post('/duplicates/{group}/resolve', [MdmController::class, 'resolveDuplicate'])->name('duplicates.resolve');
        Route::post('/records/{entityType}/{entityId}/archive', [MdmController::class, 'archive'])->name('records.archive');
        Route::get('/relationships', [MdmController::class, 'relationships'])->name('relationships');
        Route::post('/relationships/sync', [MdmController::class, 'syncRelationships'])->name('relationships.sync');
        Route::get('/history', [MdmController::class, 'history'])->name('history');
        Route::post('/imports/preview', [MdmController::class, 'importPreview'])->name('imports.preview');
        Route::post('/imports/apply', [MdmController::class, 'importApply'])->name('imports.apply');
        Route::get('/change-requests', [MdmController::class, 'changeRequests'])->name('change_requests');
        Route::post('/change-requests', [MdmController::class, 'submitChangeRequest'])->name('change_requests.submit');
        Route::post('/change-requests/{changeRequest}/review', [MdmController::class, 'reviewChangeRequest'])->name('change_requests.review');
        Route::post('/records/{record}/owner', [MdmController::class, 'assignOwner'])->name('records.owner');
    });

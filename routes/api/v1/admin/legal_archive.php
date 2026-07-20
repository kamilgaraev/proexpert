<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\LegalArchiveController;
use App\Http\Controllers\Api\V1\Admin\LegalDocumentEditorController;
use App\Http\Controllers\Api\V1\Admin\LegalDocumentVersionAccessController;
use Illuminate\Support\Facades\Route;

Route::prefix('legal-archive')->name('legal-archive.')->group(function (): void {
    Route::get('dictionaries', [LegalArchiveController::class, 'dictionaries'])
        ->middleware('authorize:legal_archive.view')
        ->name('dictionaries');

    Route::get('documents', [LegalArchiveController::class, 'index'])
        ->name('documents.index');

    Route::post('documents', [LegalArchiveController::class, 'store'])
        ->middleware('authorize:legal_archive.create')
        ->name('documents.store');

    Route::get('document-recoveries', [LegalArchiveController::class, 'recoveryIndex'])
        ->middleware('authorize:legal_archive.create')
        ->name('document-recoveries.index');

    Route::post('document-recoveries/{operation}', [LegalArchiveController::class, 'recoverCreate'])
        ->middleware('authorize:legal_archive.create')
        ->whereUuid('operation')
        ->name('document-recoveries.recover');

    Route::get('documents/{document}', [LegalArchiveController::class, 'show'])
        ->name('documents.show');

    Route::patch('documents/{document}', [LegalArchiveController::class, 'update'])
        ->middleware('authorize:legal_archive.update')
        ->name('documents.update');

    Route::post('documents/{document}/management-recovery', [LegalArchiveController::class, 'recoverManagement'])
        ->middleware('authorize:legal_archive.security_recovery.manage')
        ->name('documents.management-recovery');

    Route::post('documents/{document}/versions', [LegalArchiveController::class, 'storeVersion'])
        ->middleware(['authorize:legal_archive.versions.create', 'authorize:legal_archive.files.upload'])
        ->name('documents.versions.store');

    Route::get('documents/{document}/current-version', [LegalArchiveController::class, 'currentVersion'])
        ->name('documents.current-version');

    Route::post('document-file-versions/{version}/editor/session', [LegalDocumentEditorController::class, 'open'])
        ->middleware('authorize:legal_archive.view')->name('document-file-versions.editor.session');
    Route::get('document-file-versions/{version}/preview', [LegalDocumentVersionAccessController::class, 'preview'])
        ->middleware('authorize:legal_archive.view')->name('document-file-versions.preview');
    Route::get('document-file-versions/{version}/download', [LegalDocumentVersionAccessController::class, 'download'])
        ->middleware('authorize:legal_archive.files.download')->name('document-file-versions.download');
});

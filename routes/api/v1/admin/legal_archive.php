<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\LegalArchiveController;
use Illuminate\Support\Facades\Route;

Route::prefix('legal-archive')->name('legal-archive.')->group(function (): void {
    Route::get('dictionaries', [LegalArchiveController::class, 'dictionaries'])
        ->middleware('authorize:legal_archive.view')
        ->name('dictionaries');

    Route::get('documents', [LegalArchiveController::class, 'index'])
        ->middleware('authorize:legal_archive.view')
        ->name('documents.index');

    Route::post('documents', [LegalArchiveController::class, 'store'])
        ->middleware('authorize:legal_archive.create')
        ->name('documents.store');

    Route::get('documents/{document}', [LegalArchiveController::class, 'show'])
        ->middleware('authorize:legal_archive.view')
        ->name('documents.show');

    Route::patch('documents/{document}', [LegalArchiveController::class, 'update'])
        ->middleware('authorize:legal_archive.update')
        ->name('documents.update');

    Route::post('documents/{document}/versions', [LegalArchiveController::class, 'storeVersion'])
        ->middleware('authorize:legal_archive.versions.create')
        ->name('documents.versions.store');

    Route::get('documents/{document}/current-version', [LegalArchiveController::class, 'currentVersion'])
        ->middleware('authorize:legal_archive.view')
        ->name('documents.current-version');
});

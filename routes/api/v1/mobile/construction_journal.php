<?php

use App\Http\Controllers\Api\V1\Mobile\ConstructionJournalController;
use App\Http\Controllers\Api\V1\Mobile\ConstructionJournalEntryController;
use App\Http\Controllers\Api\V1\Mobile\JournalExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function () {
    Route::get('/construction-journals', [ConstructionJournalController::class, 'index'])->name('construction-journals.index');
    Route::post('/construction-journals', [ConstructionJournalController::class, 'store'])->name('construction-journals.store');
    Route::get('/construction-journals/{journal}', [ConstructionJournalController::class, 'show'])->name('construction-journals.show');
    Route::put('/construction-journals/{journal}', [ConstructionJournalController::class, 'update'])->name('construction-journals.update');

    Route::get('/construction-journals/{journal}/entries', [ConstructionJournalController::class, 'entries'])
        ->name('construction-journals.entries.index');
    Route::post('/construction-journals/{journal}/entries', [ConstructionJournalEntryController::class, 'store'])
        ->name('construction-journals.entries.store');

    Route::get('/journal-entries/{entry}', [ConstructionJournalEntryController::class, 'show'])->name('journal-entries.show');
    Route::put('/journal-entries/{entry}', [ConstructionJournalEntryController::class, 'update'])->name('journal-entries.update');
    Route::delete('/journal-entries/{entry}', [ConstructionJournalEntryController::class, 'destroy'])->name('journal-entries.destroy');
    Route::post('/journal-entries/{entry}/submit', [ConstructionJournalEntryController::class, 'submit'])->name('journal-entries.submit');
    Route::post('/journal-entries/{entry}/approve', [ConstructionJournalEntryController::class, 'approve'])->name('journal-entries.approve');
    Route::post('/journal-entries/{entry}/reject', [ConstructionJournalEntryController::class, 'reject'])->name('journal-entries.reject');

    Route::post('/construction-journals/{journal}/export/ks6', [JournalExportController::class, 'exportKS6'])
        ->name('construction-journals.export.ks6');
    Route::post('/construction-journals/{journal}/export/extended', [JournalExportController::class, 'exportExtended'])
        ->name('construction-journals.export.extended');
    Route::post('/journal-entries/{entry}/export/daily-report', [JournalExportController::class, 'exportDailyReport'])
        ->name('journal-entries.export.daily-report');
});

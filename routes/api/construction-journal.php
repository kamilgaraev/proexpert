<?php

use App\Http\Controllers\Api\ConstructionJournalController;
use App\Http\Controllers\Api\ConstructionJournalEntryController;
use App\Http\Controllers\Api\JournalExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Construction Journal API Routes
|--------------------------------------------------------------------------
|
| Маршруты для работы с Общим журналом работ (ОЖР, форма КС-6)
|
*/

Route::middleware(['auth:api_admin'])->group(function () {
    
    // Журналы работ
    Route::prefix('projects/{project}')->group(function () {
        Route::get('construction-journals', [ConstructionJournalController::class, 'index'])
            ->name('projects.construction-journals.index');
        Route::post('construction-journals', [ConstructionJournalController::class, 'store'])
            ->name('projects.construction-journals.store');
    });

    Route::prefix('construction-journals/{journal}')->group(function () {
        Route::get('/', [ConstructionJournalController::class, 'show'])
            ->name('construction-journals.show');
        Route::put('/', [ConstructionJournalController::class, 'update'])
            ->name('construction-journals.update');
        Route::delete('/', [ConstructionJournalController::class, 'destroy'])
            ->name('construction-journals.destroy');
        
        // Записи журнала
        Route::get('entries', [ConstructionJournalController::class, 'entries'])
            ->name('construction-journals.entries.index');
        Route::post('entries', [ConstructionJournalEntryController::class, 'store'])
            ->name('construction-journals.entries.store');
    });

    // Записи журнала (отдельные операции)
    Route::prefix('journal-entries/{entry}')->group(function () {
        Route::get('/', [ConstructionJournalEntryController::class, 'show'])
            ->name('journal-entries.show');
        Route::put('/', [ConstructionJournalEntryController::class, 'update'])
            ->name('journal-entries.update');
        Route::delete('/', [ConstructionJournalEntryController::class, 'destroy'])
            ->name('journal-entries.destroy');
        
        // Workflow утверждения
        Route::post('submit', [ConstructionJournalEntryController::class, 'submit'])
            ->name('journal-entries.submit');
        Route::post('approve', [ConstructionJournalEntryController::class, 'approve'])
            ->name('journal-entries.approve');
        Route::post('reject', [ConstructionJournalEntryController::class, 'reject'])
            ->name('journal-entries.reject');
    });

    // Экспорт документов
    Route::prefix('construction-journals/{journal}/export')->group(function () {
        Route::post('ks6', [JournalExportController::class, 'exportKS6'])
            ->name('construction-journals.export.ks6');
        Route::post('extended', [JournalExportController::class, 'exportExtended'])
            ->name('construction-journals.export.extended');
    });

    Route::post('journal-entries/{entry}/export/daily-report', [JournalExportController::class, 'exportDailyReport'])
        ->name('journal-entries.export.daily-report');
});


<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\OneCExchangeController;
use Illuminate\Support\Facades\Route;

Route::prefix('one-c-exchange')->name('one-c-exchange.')->group(function (): void {
    Route::get('/status', [OneCExchangeController::class, 'status'])
        ->middleware('authorize:one_c_exchange.view')
        ->name('status');
    Route::get('/monitoring', [OneCExchangeController::class, 'monitoring'])
        ->middleware('authorize:one_c_exchange.view')
        ->name('monitoring');
    Route::get('/health', [OneCExchangeController::class, 'health'])
        ->middleware('authorize:one_c_exchange.view')
        ->name('health');
    Route::get('/metrics', [OneCExchangeController::class, 'metrics'])
        ->middleware('authorize:one_c_exchange.view')
        ->name('metrics');
    Route::get('/tokens', [OneCExchangeController::class, 'tokens'])
        ->middleware('authorize:one_c_exchange.manage_tokens')
        ->name('tokens');
    Route::post('/tokens', [OneCExchangeController::class, 'createToken'])
        ->middleware('authorize:one_c_exchange.manage_tokens')
        ->name('tokens.create');
    Route::delete('/tokens/{tokenId}', [OneCExchangeController::class, 'revokeToken'])
        ->middleware('authorize:one_c_exchange.manage_tokens')
        ->name('tokens.revoke');
    Route::get('/mappings', [OneCExchangeController::class, 'mappings'])
        ->middleware('authorize:one_c_exchange.manage_mappings')
        ->name('mappings');
    Route::post('/mappings', [OneCExchangeController::class, 'storeMapping'])
        ->middleware('authorize:one_c_exchange.manage_mappings')
        ->name('mappings.store');
    Route::get('/reference-mappings', [OneCExchangeController::class, 'referenceMappings'])
        ->middleware('authorize:one_c_exchange.manage_mappings')
        ->name('reference-mappings');
    Route::get('/reference-mappings/{mappingId}', [OneCExchangeController::class, 'showReferenceMapping'])
        ->middleware('authorize:one_c_exchange.manage_mappings')
        ->name('reference-mappings.show');
    Route::post('/reference-mappings', [OneCExchangeController::class, 'storeReferenceMapping'])
        ->middleware('authorize:one_c_exchange.manage_mappings')
        ->name('reference-mappings.store');
    Route::post('/import', [OneCExchangeController::class, 'import'])
        ->middleware('authorize:one_c_exchange.import')
        ->name('import');
    Route::post('/export', [OneCExchangeController::class, 'export'])
        ->middleware('authorize:one_c_exchange.export')
        ->name('export');
    Route::get('/history', [OneCExchangeController::class, 'history'])
        ->middleware('authorize:one_c_exchange.history.view')
        ->name('history');
    Route::get('/journal', [OneCExchangeController::class, 'journal'])
        ->middleware('authorize:one_c_exchange.history.view')
        ->name('journal');
    Route::get('/journal/{operationId}', [OneCExchangeController::class, 'showJournalOperation'])
        ->middleware('authorize:one_c_exchange.history.view')
        ->name('journal.show');
    Route::post('/journal/{operationId}/retry', [OneCExchangeController::class, 'retryJournalOperation'])
        ->middleware('authorize:one_c_exchange.retry')
        ->name('journal.retry');
    Route::post('/journal/{operationId}/dead-letter', [OneCExchangeController::class, 'deadLetterJournalOperation'])
        ->middleware('authorize:one_c_exchange.dead_letter.manage')
        ->name('journal.dead-letter');
});

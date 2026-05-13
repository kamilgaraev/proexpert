<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\OneCExchangeController;
use Illuminate\Support\Facades\Route;

Route::prefix('one-c-exchange')->name('one-c-exchange.')->group(function (): void {
    Route::get('/status', [OneCExchangeController::class, 'status'])
        ->middleware('authorize:one_c_exchange.view')
        ->name('status');
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
    Route::post('/import', [OneCExchangeController::class, 'import'])
        ->middleware('authorize:one_c_exchange.import')
        ->name('import');
    Route::post('/export', [OneCExchangeController::class, 'export'])
        ->middleware('authorize:one_c_exchange.export')
        ->name('export');
    Route::get('/history', [OneCExchangeController::class, 'history'])
        ->middleware('authorize:one_c_exchange.history.view')
        ->name('history');
});

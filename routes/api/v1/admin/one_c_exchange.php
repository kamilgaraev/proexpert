<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\OneCExchangeController;
use Illuminate\Support\Facades\Route;

Route::prefix('one-c-exchange')->name('one-c-exchange.')->group(function (): void {
    Route::get('/status', [OneCExchangeController::class, 'status'])->name('status');
    Route::get('/tokens', [OneCExchangeController::class, 'tokens'])->name('tokens');
    Route::post('/tokens', [OneCExchangeController::class, 'createToken'])->name('tokens.create');
    Route::delete('/tokens/{tokenId}', [OneCExchangeController::class, 'revokeToken'])->name('tokens.revoke');
    Route::get('/mappings', [OneCExchangeController::class, 'mappings'])->name('mappings');
    Route::post('/mappings', [OneCExchangeController::class, 'storeMapping'])->name('mappings.store');
    Route::post('/import', [OneCExchangeController::class, 'import'])->name('import');
    Route::post('/export', [OneCExchangeController::class, 'export'])->name('export');
    Route::get('/history', [OneCExchangeController::class, 'history'])->name('history');
});

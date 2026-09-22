<?php

use App\Http\Controllers\Api\V1\Admin\MaterialConsumptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('material-consumption')->group(function (): void {
    Route::post('rates', [MaterialConsumptionController::class, 'storeRate'])
        ->middleware('authorize:materials.consumption_rates.edit')
        ->name('material-consumption.rates.store');
    Route::get('rates/{rate}', [MaterialConsumptionController::class, 'showRate'])
        ->middleware('authorize:materials.consumption_rates.view')
        ->name('material-consumption.rates.show');
    Route::post('rates/{rate}/approve', [MaterialConsumptionController::class, 'approveRate'])
        ->middleware('authorize:materials.consumption_rates.edit')
        ->name('material-consumption.rates.approve');

    Route::post('facts', [MaterialConsumptionController::class, 'storeFact'])
        ->middleware('authorize:materials.consumption_rates.edit')
        ->name('material-consumption.facts.store');
    Route::get('facts/{fact}', [MaterialConsumptionController::class, 'showFact'])
        ->middleware('authorize:materials.consumption_rates.view')
        ->name('material-consumption.facts.show');

    Route::post('statements', [MaterialConsumptionController::class, 'storeStatement'])
        ->middleware('authorize:materials.consumption_rates.edit')
        ->name('material-consumption.statements.store');
    Route::get('statements/{statement}', [MaterialConsumptionController::class, 'showStatement'])
        ->middleware('authorize:materials.consumption_rates.view')
        ->name('material-consumption.statements.show');
    Route::post('statements/{statement}/approve', [MaterialConsumptionController::class, 'approveStatement'])
        ->middleware('authorize:materials.consumption_rates.edit')
        ->name('material-consumption.statements.approve');
    Route::get('statements/{statement}/export/xlsx', [MaterialConsumptionController::class, 'exportStatementXlsx'])
        ->middleware('authorize:materials.export')
        ->name('material-consumption.statements.export.xlsx');
    Route::get('statements/{statement}/export/pdf', [MaterialConsumptionController::class, 'exportStatementPdf'])
        ->middleware('authorize:materials.export')
        ->name('material-consumption.statements.export.pdf');
});

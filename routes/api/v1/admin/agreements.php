<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\AgreementController;

// Доп. соглашения
// Индекс отдаётся в контексте контракта: /admin/contracts/{contract}/agreements
Route::group(['prefix' => 'contracts/{contract}'], function () {
    Route::get('agreements', [AgreementController::class, 'index'])
        ->name('contracts.agreements.index');
});

// CRUD по соглашениям (без индекса)
Route::apiResource('agreements', AgreementController::class)
    ->parameters(['agreements' => 'agreement']);

// Применение изменений дополнительного соглашения к контракту
Route::post('agreements/{agreement}/apply-changes', [AgreementController::class, 'applyChanges'])
    ->name('agreements.apply-changes'); 
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ContractAllocationController;

/**
 * Маршруты для управления распределением контрактов по проектам
 * 
 * ВАЖНО: Этот файл подключается внутри группы с префиксом /api/v1/admin
 * и всеми необходимыми middleware из routes/api.php
 * Поэтому здесь НЕ нужно повторять префикс и middleware
 */

// Управление распределениями контракта
Route::prefix('contracts/{contractId}/allocations')->name('contracts.allocations.')->group(function () {
    Route::get('summary', [ContractAllocationController::class, 'summary'])->middleware('authorize:contracts.view')->name('summary');
    Route::get('', [ContractAllocationController::class, 'index'])->middleware('authorize:contracts.view')->name('index');
    Route::post('sync', [ContractAllocationController::class, 'sync'])->middleware('authorize:contracts.edit')->name('sync');
    Route::post('auto-equal', [ContractAllocationController::class, 'createAutoEqual'])->middleware('authorize:contracts.edit')->name('auto_equal');
    Route::post('auto-acts', [ContractAllocationController::class, 'createBasedOnActs'])->middleware('authorize:contracts.edit')->name('auto_acts');
    Route::post('recalculate', [ContractAllocationController::class, 'recalculate'])->middleware('authorize:contracts.edit')->name('recalculate');
});

// Управление отдельными распределениями
Route::prefix('allocations/{allocationId}')->name('allocations.')->group(function () {
    Route::post('convert-to-fixed', [ContractAllocationController::class, 'convertToFixed'])->middleware('authorize:contracts.edit')->name('convert_to_fixed');
    Route::delete('', [ContractAllocationController::class, 'destroy'])->middleware('authorize:contracts.edit')->name('destroy');
    Route::get('history', [ContractAllocationController::class, 'history'])->middleware('authorize:contracts.view')->name('history');
});


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
    Route::get('summary', [ContractAllocationController::class, 'summary'])->name('summary');
    Route::get('', [ContractAllocationController::class, 'index'])->name('index');
    Route::post('sync', [ContractAllocationController::class, 'sync'])->name('sync');
    Route::post('auto-equal', [ContractAllocationController::class, 'createAutoEqual'])->name('auto_equal');
    Route::post('auto-acts', [ContractAllocationController::class, 'createBasedOnActs'])->name('auto_acts');
    Route::post('recalculate', [ContractAllocationController::class, 'recalculate'])->name('recalculate');
});

// Управление отдельными распределениями
Route::prefix('allocations/{allocationId}')->name('allocations.')->group(function () {
    Route::post('convert-to-fixed', [ContractAllocationController::class, 'convertToFixed'])->name('convert_to_fixed');
    Route::delete('', [ContractAllocationController::class, 'destroy'])->name('destroy');
    Route::get('history', [ContractAllocationController::class, 'history'])->name('history');
});


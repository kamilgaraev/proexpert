<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractPerformanceActController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractPaymentController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractSpecificationController;

// Маршруты для Контрактов
// Префикс 'admin' и middleware применяются в главном файле routes/api.php
Route::apiResource('contracts', ContractController::class);

// Дополнительные маршруты для контрактов
Route::group(['prefix' => 'contracts'], function () {
    Route::get('{contract}/full', [ContractController::class, 'fullDetails'])
        ->name('contracts.full-details');
    Route::get('{contract}/analytics', [ContractController::class, 'analytics'])
        ->name('contracts.analytics');
    Route::get('{contract}/completed-works', [ContractController::class, 'completedWorks'])
        ->name('contracts.completed-works');
    Route::get('{contract}/available-works-for-acts', [ContractPerformanceActController::class, 'availableWorks'])
        ->name('contracts.available-works-for-acts');
});

// Вложенные маршруты для Актов выполненных работ к Контрактам
// Имена параметров будут contract и performance_act
// Доступ: admin/contracts/{contract}/performance-acts
//         admin/performance-acts/{performance_act} (благодаря shallow)
Route::apiResource('contracts.performance-acts', ContractPerformanceActController::class)
    ->shallow() 
    ->parameters(['performance-acts' => 'performance_act']);

// Дополнительные маршруты для экспорта актов и файлов
Route::group(['prefix' => 'contracts/{contract}/performance-acts'], function () {
    Route::get('{performance_act}/export/pdf', [ContractPerformanceActController::class, 'exportPdf'])
        ->name('contracts.performance-acts.export.pdf');
    Route::get('{performance_act}/export/excel', [ContractPerformanceActController::class, 'exportExcel'])
        ->name('contracts.performance-acts.export.excel');
});

// Маршруты для файлов актов (shallow - без привязки к контракту)
Route::get('performance-acts/{performance_act}/files', [ContractPerformanceActController::class, 'getFiles'])
    ->name('performance-acts.files');

// Вложенные маршруты для Платежей по Контрактам
Route::apiResource('contracts.payments', ContractPaymentController::class)
    ->shallow()
    ->parameters(['payments' => 'payment']);

// Маршруты для Спецификаций контрактов
Route::group(['prefix' => 'contracts/{contract}/specifications'], function () {
    Route::get('/', [ContractSpecificationController::class, 'index'])
        ->name('contracts.specifications.index');
    Route::post('/', [ContractSpecificationController::class, 'store'])
        ->name('contracts.specifications.store');
    Route::post('attach', [ContractSpecificationController::class, 'attach'])
        ->name('contracts.specifications.attach');
    Route::delete('{specification}', [ContractSpecificationController::class, 'destroy'])
        ->name('contracts.specifications.destroy');
});

?> 
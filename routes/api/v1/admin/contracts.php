<?php

use App\Http\Controllers\Api\V1\Admin\Contract\ContractPerformanceActController;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use Illuminate\Support\Facades\Route;

// ContractPaymentController удален - используйте модуль Payments

// Маршруты для Контрактов
// Префикс 'admin' и middleware применяются в главном файле routes/api.php
Route::get('contracts', [ContractController::class, 'index'])
    ->middleware('authorize:contracts.view')
    ->name('contracts.index');
Route::post('contracts', [ContractController::class, 'store'])
    ->middleware('authorize:contracts.create')
    ->name('contracts.store');
Route::get('contracts/{contract}', [ContractController::class, 'show'])
    ->middleware('authorize:contracts.view')
    ->name('contracts.show');
Route::match(['put', 'patch'], 'contracts/{contract}', [ContractController::class, 'update'])
    ->middleware('authorize:contracts.edit')
    ->name('contracts.update');
Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])
    ->middleware('authorize:contracts.delete')
    ->name('contracts.destroy');

foreach (['activate', 'suspend', 'resume', 'complete', 'terminate'] as $action) {
    Route::post("contracts/{contract}/{$action}", [ContractController::class, 'transition'])
        ->defaults('action', $action)
        ->middleware('authorize:contracts.edit')
        ->name("contracts.{$action}");
}

Route::post('contracts/{contract}/archive', [ContractController::class, 'transition'])
    ->defaults('action', 'archive')
    ->middleware('authorize:contracts.archive')
    ->name('contracts.archive');

// Дополнительные маршруты для контрактов
Route::group(['prefix' => 'contracts'], function () {
    Route::get('{contract}/full', [ContractController::class, 'fullDetails'])
        ->name('contracts.full-details');
    Route::post('{contract}/resolve-side-review', [ContractController::class, 'resolveSideReview'])
        ->name('contracts.resolve-side-review');
    Route::get('{contract}/analytics', [ContractController::class, 'analytics'])
        ->name('contracts.analytics');
    Route::get('{contract}/completed-works', [ContractController::class, 'completedWorks'])
        ->name('contracts.completed-works');
    Route::get('{contract}/export-ks6a', [ContractController::class, 'exportKS6a'])
        ->name('contracts.export-ks6a');
    Route::get('{contract}/available-works-for-acts', [ContractPerformanceActController::class, 'availableWorks'])
        ->middleware('authorize:contracts.performance_acts.create')
        ->name('contracts.available-works-for-acts');
});

// Вложенные маршруты для Актов выполненных работ к Контрактам
// Имена параметров будут contract и performance_act
// Доступ: admin/contracts/{contract}/performance-acts
//         admin/performance-acts/{performance_act} (благодаря shallow)
Route::get('contracts/{contract}/performance-acts', [ContractPerformanceActController::class, 'index'])
    ->middleware('authorize:contracts.performance_acts.view')
    ->name('contracts.performance-acts.index');
Route::post('contracts/{contract}/performance-acts', [ContractPerformanceActController::class, 'store'])
    ->middleware('authorize:contracts.performance_acts.create')
    ->name('contracts.performance-acts.store');
Route::get('performance-acts/{performance_act}', [ContractPerformanceActController::class, 'show'])
    ->middleware('authorize:contracts.performance_acts.view')
    ->name('performance-acts.show');
Route::match(['put', 'patch'], 'performance-acts/{performance_act}', [ContractPerformanceActController::class, 'update'])
    ->middleware('authorize:contracts.performance_acts.edit')
    ->name('performance-acts.update');
Route::delete('performance-acts/{performance_act}', [ContractPerformanceActController::class, 'destroy'])
    ->middleware('authorize:contracts.performance_acts.delete')
    ->name('performance-acts.destroy');

// Дополнительные маршруты для экспорта актов и файлов
Route::group(['prefix' => 'contracts/{contract}/performance-acts'], function () {
    Route::get('{performance_act}/export/pdf', [ContractPerformanceActController::class, 'exportPdf'])
        ->middleware('authorize:contracts.performance_acts.export')
        ->name('contracts.performance-acts.export.pdf');
    Route::get('{performance_act}/export/excel', [ContractPerformanceActController::class, 'exportExcel'])
        ->middleware('authorize:contracts.performance_acts.export')
        ->name('contracts.performance-acts.export.excel');
    Route::get('{performance_act}/export/ks3', [ContractPerformanceActController::class, 'exportKS3'])
        ->middleware('authorize:contracts.performance_acts.export')
        ->name('contracts.performance-acts.export.ks3');
});

// Маршруты для файлов актов (shallow - без привязки к контракту)
Route::get('performance-acts/{performance_act}/files', [ContractPerformanceActController::class, 'getFiles'])
    ->middleware('authorize:contracts.performance_acts.view')
    ->name('performance-acts.files');

// УСТАРЕВШИЕ МАРШРУТЫ - УДАЛЕНЫ
// Платежи по контрактам теперь управляются через модуль Payments
// Используйте: /api/v1/admin/payments/invoices
// Старые маршруты contracts.payments больше не поддерживаются

// ПРИМЕЧАНИЕ: Маршруты для спецификаций и state-events перенесены в project-based.php
// Используйте маршруты: /api/v1/admin/projects/{project}/contracts/{contract}/...

// Маршруты для распределения контрактов по проектам (allocations)
require __DIR__.'/contract_allocations.php';

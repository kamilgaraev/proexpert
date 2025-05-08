<?php

use Illuminate\Support\Facades\Route;

// Маршруты для транзакций подотчетных средств
Route::prefix('advance-transactions')->name('advance-transactions.')->group(function () {
    // Получение доступных пользователей для транзакций
    Route::get('/available-users', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'getAvailableUsers'])
        ->name('available-users');
    
    // Получение доступных проектов для транзакций
    Route::get('/available-projects', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'getAvailableProjects'])
        ->name('available-projects');
    
    // CRUD маршруты для транзакций
    Route::get('/', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'index'])
        ->name('index');
    Route::post('/', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'store'])
        ->name('store');
    Route::get('/{transaction}', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'show'])
        ->name('show');
    Route::put('/{transaction}', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'update'])
        ->name('update');
    Route::delete('/{transaction}', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'destroy'])
        ->name('destroy');
    
    // Дополнительные действия с транзакциями
    Route::post('/{transaction}/report', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'report'])
        ->name('report');
    Route::post('/{transaction}/approve', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'approve'])
        ->name('approve');
    Route::post('/{transaction}/attachments', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'attachFiles'])
        ->name('attach-files');
    Route::delete('/{transaction}/attachments/{fileId}', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController::class, 'detachFile'])
        ->name('detach-file');
});

// Маршруты для работы с балансом пользователей
Route::prefix('users/{user}')->name('users.')->group(function () {
    Route::get('/advance-balance', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'getAdvanceBalance'])
        ->name('advance-balance');
    Route::get('/advance-transactions', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'getAdvanceTransactions'])
        ->name('advance-transactions');
    Route::post('/issue-funds', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'issueFunds'])
        ->name('issue-funds');
    Route::post('/return-funds', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'returnFunds'])
        ->name('return-funds');
});

// Маршруты отчетов по подотчетным средствам
Route::prefix('reports/advance-accounts')->name('reports.advance-accounts.')->group(function () {
    Route::get('/summary', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountReportController::class, 'summary'])
        ->name('summary');
    
    Route::get('/users/{userId}', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountReportController::class, 'userReport'])
        ->name('user');
    
    Route::get('/projects/{projectId}', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountReportController::class, 'projectReport'])
        ->name('project');
    
    Route::get('/overdue', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountReportController::class, 'overdueReport'])
        ->name('overdue');
    
    Route::get('/export/{format}', [\App\Http\Controllers\Api\V1\Admin\AdvanceAccountReportController::class, 'export'])
        ->name('export');
});

// Маршруты интеграции с бухгалтерскими системами
Route::prefix('accounting')->name('accounting.')->group(function () {
    Route::post('/import-users', [\App\Http\Controllers\Api\V1\Admin\AccountingIntegrationController::class, 'importUsers'])
        ->name('import-users');
    
    Route::post('/import-projects', [\App\Http\Controllers\Api\V1\Admin\AccountingIntegrationController::class, 'importProjects'])
        ->name('import-projects');
    
    Route::post('/import-materials', [\App\Http\Controllers\Api\V1\Admin\AccountingIntegrationController::class, 'importMaterials'])
        ->name('import-materials');
    
    Route::post('/export-transactions', [\App\Http\Controllers\Api\V1\Admin\AccountingIntegrationController::class, 'exportTransactions'])
        ->name('export-transactions');
    
    Route::get('/sync-status', [\App\Http\Controllers\Api\V1\Admin\AccountingIntegrationController::class, 'getSyncStatus'])
        ->name('sync-status');
}); 
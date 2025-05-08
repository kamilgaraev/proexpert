<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\AdvanceAccountReportController;
use App\Http\Controllers\Api\V1\Admin\AccountingIntegrationController;

// Маршруты для транзакций подотчетных средств
Route::prefix('advance-transactions')->name('advance-transactions.')->group(function () {
    // Получение доступных пользователей для транзакций
    Route::get('/available-users', [AdvanceAccountTransactionController::class, 'getAvailableUsers'])
        ->name('available-users');
    
    // Получение доступных проектов для транзакций
    Route::get('/available-projects', [AdvanceAccountTransactionController::class, 'getAvailableProjects'])
        ->name('available-projects');
    
    // CRUD маршруты для транзакций
    Route::get('/', [AdvanceAccountTransactionController::class, 'index'])
        ->name('index');
    Route::post('/', [AdvanceAccountTransactionController::class, 'store'])
        ->name('store');
    Route::get('/{transaction}', [AdvanceAccountTransactionController::class, 'show'])
        ->name('show');
    Route::put('/{transaction}', [AdvanceAccountTransactionController::class, 'update'])
        ->name('update');
    Route::delete('/{transaction}', [AdvanceAccountTransactionController::class, 'destroy'])
        ->name('destroy');
    
    // Дополнительные действия с транзакциями
    Route::post('/{transaction}/report', [AdvanceAccountTransactionController::class, 'report'])
        ->name('report');
    Route::post('/{transaction}/approve', [AdvanceAccountTransactionController::class, 'approve'])
        ->name('approve');
    Route::post('/{transaction}/attachments', [AdvanceAccountTransactionController::class, 'attachFiles'])
        ->name('attach-files');
    Route::delete('/{transaction}/attachments/{fileId}', [AdvanceAccountTransactionController::class, 'detachFile'])
        ->name('detach-file');
});

// Маршруты для работы с балансом пользователей
Route::prefix('users/{user}')->name('users.')->group(function () {
    Route::get('/advance-balance', [UserController::class, 'getAdvanceBalance'])
        ->name('advance-balance');
    Route::get('/advance-transactions', [UserController::class, 'getAdvanceTransactions'])
        ->name('advance-transactions');
    Route::post('/issue-funds', [UserController::class, 'issueFunds'])
        ->name('issue-funds');
    Route::post('/return-funds', [UserController::class, 'returnFunds'])
        ->name('return-funds');
});

// Маршруты отчетов по подотчетным средствам
Route::prefix('reports/advance-accounts')->name('reports.advance-accounts.')->group(function () {
    Route::get('/summary', [AdvanceAccountReportController::class, 'summary'])
        ->name('summary');
    
    Route::get('/users/{userId}', [AdvanceAccountReportController::class, 'userReport'])
        ->name('user');
    
    Route::get('/projects/{projectId}', [AdvanceAccountReportController::class, 'projectReport'])
        ->name('project');
    
    Route::get('/overdue', [AdvanceAccountReportController::class, 'overdueReport'])
        ->name('overdue');
    
    Route::get('/export/{format}', [AdvanceAccountReportController::class, 'export'])
        ->name('export');
});

// Маршруты интеграции с бухгалтерскими системами
Route::prefix('accounting')->name('accounting.')->group(function () {
    Route::post('/import-users', [AccountingIntegrationController::class, 'importUsers'])
        ->name('import-users');
    
    Route::post('/import-projects', [AccountingIntegrationController::class, 'importProjects'])
        ->name('import-projects');
    
    Route::post('/import-materials', [AccountingIntegrationController::class, 'importMaterials'])
        ->name('import-materials');
    
    Route::post('/export-transactions', [AccountingIntegrationController::class, 'exportTransactions'])
        ->name('export-transactions');
    
    Route::get('/sync-status', [AccountingIntegrationController::class, 'getSyncStatus'])
        ->name('sync-status');
}); 
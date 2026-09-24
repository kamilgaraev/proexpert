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
        ->middleware('authorize:advance_transactions.create')
        ->name('available-users');
    
    // Получение доступных проектов для транзакций
    Route::get('/available-projects', [AdvanceAccountTransactionController::class, 'getAvailableProjects'])
        ->middleware('authorize:advance_transactions.create')
        ->name('available-projects');

    // Статистика по транзакциям
    Route::get('/stats', [AdvanceAccountTransactionController::class, 'stats'])
        ->middleware('authorize:advance_transactions.view')
        ->name('stats');
    
    // CRUD маршруты для транзакций
    Route::get('/', [AdvanceAccountTransactionController::class, 'index'])
        ->middleware('authorize:advance_transactions.view')
        ->name('index');
    Route::post('/', [AdvanceAccountTransactionController::class, 'store'])
        ->middleware('authorize:advance_transactions.create')
        ->name('store');
    Route::get('/{transaction}', [AdvanceAccountTransactionController::class, 'show'])
        ->middleware('authorize:advance_transactions.view')
        ->name('show')->whereNumber('transaction');
    Route::put('/{transaction}', [AdvanceAccountTransactionController::class, 'update'])
        ->middleware('authorize:advance_transactions.edit')
        ->name('update')->whereNumber('transaction');
    Route::delete('/{transaction}', [AdvanceAccountTransactionController::class, 'destroy'])
        ->middleware('authorize:advance_transactions.delete')
        ->name('destroy')->whereNumber('transaction');
    
    // Дополнительные действия с транзакциями
    Route::post('/{transaction}/report', [AdvanceAccountTransactionController::class, 'report'])
        ->middleware('authorize:advance_transactions.report')
        ->name('report')->whereNumber('transaction');
    Route::post('/{transaction}/approve', [AdvanceAccountTransactionController::class, 'approve'])
        ->middleware('authorize:advance_transactions.approve')
        ->name('approve')->whereNumber('transaction');
    Route::post('/{transaction}/attachments', [AdvanceAccountTransactionController::class, 'attachFiles'])
        ->middleware('authorize:advance_transactions.files.manage')
        ->name('attach-files')->whereNumber('transaction');
    Route::delete('/{transaction}/attachments/{fileId}', [AdvanceAccountTransactionController::class, 'detachFile'])
        ->middleware('authorize:advance_transactions.files.manage')
        ->name('detach-file')->whereNumber('transaction');
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
        ->middleware('authorize:advance-accounting.reports.advance_accounts.view')
        ->name('summary');
    
    Route::get('/users/{userId}', [AdvanceAccountReportController::class, 'userReport'])
        ->middleware('authorize:advance-accounting.reports.advance_accounts.view')
        ->name('user');
    
    Route::get('/projects/{projectId}', [AdvanceAccountReportController::class, 'projectReport'])
        ->middleware('authorize:advance-accounting.reports.advance_accounts.view')
        ->name('project');
    
    Route::get('/overdue', [AdvanceAccountReportController::class, 'overdueReport'])
        ->middleware('authorize:advance-accounting.reports.advance_accounts.view')
        ->name('overdue');
    
    Route::get('/export/{format}', [AdvanceAccountReportController::class, 'export'])
        ->middleware('authorize:advance-accounting.reports.advance_accounts.export')
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

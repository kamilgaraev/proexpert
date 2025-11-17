<?php

use App\BusinessModules\Core\Payments\Http\Controllers\InvoiceController;
use App\BusinessModules\Core\Payments\Http\Controllers\DashboardController;
use App\BusinessModules\Core\Payments\Http\Controllers\TransactionController;
use App\BusinessModules\Core\Payments\Http\Controllers\ScheduleController;
use App\BusinessModules\Core\Payments\Http\Controllers\CounterpartyAccountController;
use App\BusinessModules\Core\Payments\Http\Controllers\ReconciliationController;
use App\BusinessModules\Core\Payments\Http\Controllers\ReportController;
use App\BusinessModules\Core\Payments\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments Module Routes
|--------------------------------------------------------------------------
|
| Маршруты для модуля Payments
| Все маршруты защищены middleware: auth:api_admin, organization.context
|
*/

Route::prefix('api/v1/admin/payments')
    ->name('admin.payments.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context'])
    ->group(function () {
        
        // ============================================
        // Dashboard (Дашборд)
        // ============================================
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        
        // ============================================
        // Invoices (Счета)
        // ============================================
        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::get('/', [InvoiceController::class, 'index'])->name('index');
            Route::post('/', [InvoiceController::class, 'store'])->name('store');
            Route::get('/{id}', [InvoiceController::class, 'show'])->name('show');
            Route::put('/{id}', [InvoiceController::class, 'update'])->name('update');
            Route::delete('/{id}', [InvoiceController::class, 'destroy'])->name('destroy');
            
            // Действия над счётом
            Route::post('/{id}/pay', [InvoiceController::class, 'pay'])->name('pay');
            Route::post('/{id}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
        });
        
        // ============================================
        // Transactions (Транзакции)
        // ============================================
        Route::prefix('transactions')->name('transactions.')->group(function () {
            Route::get('/', [TransactionController::class, 'index'])->name('index');
            Route::get('/{id}', [TransactionController::class, 'show'])->name('show');
            Route::post('/{id}/approve', [TransactionController::class, 'approve'])->name('approve');
            Route::post('/{id}/reject', [TransactionController::class, 'reject'])->name('reject');
            Route::post('/{id}/refund', [TransactionController::class, 'refund'])->name('refund');
        });
        
        // ============================================
        // Schedules (Графики платежей)
        // ============================================
        Route::prefix('schedules')->name('schedules.')->group(function () {
            Route::get('/', [ScheduleController::class, 'index'])->name('index');
            Route::post('/', [ScheduleController::class, 'store'])->name('store');
        });
        
        // ============================================
        // Counterparty Accounts (Взаиморасчёты)
        // ============================================
        Route::get('/counterparty-accounts/{organizationId}', [CounterpartyAccountController::class, 'show'])
            ->name('counterparty_accounts.show');
        
        // ============================================
        // Reconciliation (Акты сверки)
        // ============================================
        Route::post('/reconciliation', [ReconciliationController::class, 'store'])
            ->name('reconciliation.store');
        
        // ============================================
        // Reports (Отчёты)
        // ============================================
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/financial', [ReportController::class, 'financial'])->name('financial');
            Route::post('/export', [ReportController::class, 'export'])->name('export');
        });
        
        // ============================================
        // Settings (Настройки)
        // ============================================
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingsController::class, 'show'])->name('show');
            Route::put('/', [SettingsController::class, 'update'])->name('update');
        });
    });


<?php

use App\BusinessModules\Core\Payments\Http\Controllers\DashboardController;
use App\BusinessModules\Core\Payments\Http\Controllers\TransactionController;
use App\BusinessModules\Core\Payments\Http\Controllers\ScheduleController;
use App\BusinessModules\Core\Payments\Http\Controllers\CounterpartyAccountController;
use App\BusinessModules\Core\Payments\Http\Controllers\ReconciliationController;
use App\BusinessModules\Core\Payments\Http\Controllers\ReportController;
use App\BusinessModules\Core\Payments\Http\Controllers\SettingsController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentDocumentController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentApprovalController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentRequestController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentReportsController;
use App\BusinessModules\Core\Payments\Http\Controllers\OffsetController;
use App\BusinessModules\Core\Payments\Http\Controllers\ExportController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentTemplatesController;
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
        // Templates (Шаблоны платежей)
        // ============================================
        Route::get('/templates', [PaymentTemplatesController::class, 'index'])->name('templates.index');
        Route::post('/calculate', [PaymentTemplatesController::class, 'calculate'])->name('calculate');
        
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
            Route::get('/templates', [ScheduleController::class, 'templates'])->name('templates');
            Route::get('/upcoming', [ScheduleController::class, 'upcoming'])->name('upcoming');
            Route::get('/overdue', [ScheduleController::class, 'overdue'])->name('overdue');
            
            // Алиас для получения PaymentDocument через schedules (перенаправление на documents)
            Route::get('/documents/{id}', [PaymentDocumentController::class, 'show'])->name('documents.show');
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
        // Reports (Отчёты) - Старые
        // ============================================
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/financial', [ReportController::class, 'financial'])->name('financial');
            Route::post('/export', [ReportController::class, 'export'])->name('export');
            
            // Новые отчеты
            Route::get('/cash-flow', [PaymentReportsController::class, 'cashFlow'])->name('cash_flow');
            Route::get('/aging-analysis', [PaymentReportsController::class, 'agingAnalysis'])->name('aging_analysis');
            Route::get('/critical-contractors', [PaymentReportsController::class, 'criticalContractors'])->name('critical_contractors');
        });
        
        // ============================================
        // Settings (Настройки)
        // ============================================
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingsController::class, 'show'])->name('show');
            Route::put('/', [SettingsController::class, 'update'])->name('update');
        });
        
        // ============================================
        // Payment Documents (Платежные документы - новая архитектура)
        // ============================================
        Route::prefix('documents')->name('documents.')->group(function () {
            // Bulk Actions
            Route::post('/bulk', [PaymentDocumentController::class, 'bulkAction'])->name('bulk_action');
            
            // Calendar
            Route::get('/calendar', [PaymentCalendarController::class, 'index'])->name('calendar.index');
            Route::post('/{id}/reschedule', [PaymentCalendarController::class, 'reschedule'])->name('calendar.reschedule');

            Route::get('/', [PaymentDocumentController::class, 'index'])->name('index');
            Route::post('/', [PaymentDocumentController::class, 'store'])->name('store');
            Route::get('/overdue', [PaymentDocumentController::class, 'overdue'])->name('overdue');
            Route::get('/upcoming', [PaymentDocumentController::class, 'upcoming'])->name('upcoming');
            Route::get('/statistics', [PaymentDocumentController::class, 'statistics'])->name('statistics');
            Route::get('/{id}', [PaymentDocumentController::class, 'show'])->name('show');
            Route::put('/{id}', [PaymentDocumentController::class, 'update'])->name('update');
            Route::delete('/{id}', [PaymentDocumentController::class, 'destroy'])->name('destroy');
            
            // Действия над документом
            Route::get('/{id}/print-order', [PaymentDocumentController::class, 'printOrder'])->name('print_order');
            Route::post('/{id}/submit', [PaymentDocumentController::class, 'submit'])->name('submit');
            Route::post('/{id}/schedule', [PaymentDocumentController::class, 'schedule'])->name('schedule');
            Route::post('/{id}/register-payment', [PaymentDocumentController::class, 'registerPayment'])->name('register_payment');
            Route::post('/{id}/cancel', [PaymentDocumentController::class, 'cancel'])->name('cancel');
            
            // Helpers
            Route::post('/generate-purpose', [PaymentDocumentController::class, 'generatePurpose'])->name('generate_purpose');
        });
        
        // ============================================
        // Payment Approvals (Утверждения платежей)
        // ============================================
        Route::prefix('approvals')->name('approvals.')->group(function () {
            Route::get('/my', [PaymentApprovalController::class, 'myApprovals'])->name('my');
            Route::post('/documents/{documentId}/approve', [PaymentApprovalController::class, 'approve'])->name('approve');
            Route::post('/documents/{documentId}/reject', [PaymentApprovalController::class, 'reject'])->name('reject');
            Route::get('/documents/{documentId}/history', [PaymentApprovalController::class, 'history'])->name('history');
            Route::get('/documents/{documentId}/status', [PaymentApprovalController::class, 'status'])->name('status');
            Route::post('/documents/{documentId}/send-reminders', [PaymentApprovalController::class, 'sendReminders'])->name('send_reminders');
        });
        
        // ============================================
        // Payment Requests (Платежные требования)
        // ============================================
        Route::prefix('requests')->name('requests.')->group(function () {
            Route::get('/incoming', [PaymentRequestController::class, 'incoming'])->name('incoming');
            Route::post('/', [PaymentRequestController::class, 'store'])->name('store');
            Route::post('/{id}/accept', [PaymentRequestController::class, 'accept'])->name('accept');
            Route::post('/{id}/reject', [PaymentRequestController::class, 'reject'])->name('reject');
            Route::get('/contractors/{contractorId}', [PaymentRequestController::class, 'fromContractor'])->name('from_contractor');
            Route::get('/statistics', [PaymentRequestController::class, 'statistics'])->name('statistics');
        });

        // ============================================
        // Offsets (Взаимозачеты)
        // ============================================
        Route::prefix('offsets')->name('offsets.')->group(function () {
            Route::get('/opportunities', [OffsetController::class, 'opportunities'])->name('opportunities');
            Route::post('/perform', [OffsetController::class, 'perform'])->name('perform');
            Route::post('/auto', [OffsetController::class, 'auto'])->name('auto');
        });
        
        // ============================================
        // Export (Экспорт)
        // ============================================
        Route::prefix('export')->name('export.')->group(function () {
            Route::post('/excel', [ExportController::class, 'excel'])->name('excel');
            Route::post('/pdf/{documentId}', [ExportController::class, 'pdf'])->name('pdf');
            Route::post('/1c', [ExportController::class, 'onec'])->name('1c');
        });
    });


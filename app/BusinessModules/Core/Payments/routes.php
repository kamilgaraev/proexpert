<?php

declare(strict_types=1);

use App\BusinessModules\Core\Payments\Http\Controllers\CounterpartyAccountController;
use App\BusinessModules\Core\Payments\Http\Controllers\DashboardController;
use App\BusinessModules\Core\Payments\Http\Controllers\ExportController;
use App\BusinessModules\Core\Payments\Http\Controllers\OffsetController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentApprovalController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentCalendarController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentDocumentController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentRecipientController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentReportsController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentRequestController;
use App\BusinessModules\Core\Payments\Http\Controllers\PaymentTemplatesController;
use App\BusinessModules\Core\Payments\Http\Controllers\ReconciliationController;
use App\BusinessModules\Core\Payments\Http\Controllers\ReportController;
use App\BusinessModules\Core\Payments\Http\Controllers\ScheduleController;
use App\BusinessModules\Core\Payments\Http\Controllers\SettingsController;
use App\BusinessModules\Core\Payments\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/payments')
    ->name('admin.payments.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context'])
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('authorize:payments.dashboard.view')
            ->name('dashboard');

        Route::get('/templates', [PaymentTemplatesController::class, 'index'])
            ->middleware('authorize:payments.invoice.view')
            ->name('templates.index');
        Route::post('/calculate', [PaymentTemplatesController::class, 'calculate'])
            ->middleware('authorize:payments.invoice.create')
            ->name('calculate');

        Route::prefix('transactions')->name('transactions.')->group(function () {
            Route::get('/', [TransactionController::class, 'index'])
                ->middleware('authorize:payments.transaction.view')
                ->name('index');
            Route::get('/{id}', [TransactionController::class, 'show'])
                ->middleware('authorize:payments.transaction.view')
                ->name('show');
            Route::post('/{id}/approve', [TransactionController::class, 'approve'])
                ->middleware('authorize:payments.transaction.approve')
                ->name('approve');
            Route::post('/{id}/reject', [TransactionController::class, 'reject'])
                ->middleware('authorize:payments.transaction.reject')
                ->name('reject');
            Route::post('/{id}/refund', [TransactionController::class, 'refund'])
                ->middleware('authorize:payments.transaction.refund')
                ->name('refund');
        });

        Route::prefix('schedules')->name('schedules.')->group(function () {
            Route::get('/', [ScheduleController::class, 'index'])
                ->middleware('authorize:payments.schedule.view')
                ->name('index');
            Route::post('/', [ScheduleController::class, 'store'])
                ->middleware('authorize:payments.schedule.create')
                ->name('store');
            Route::get('/templates', [ScheduleController::class, 'templates'])
                ->middleware('authorize:payments.schedule.view')
                ->name('templates');
            Route::get('/upcoming', [ScheduleController::class, 'upcoming'])
                ->middleware('authorize:payments.schedule.view')
                ->name('upcoming');
            Route::get('/overdue', [ScheduleController::class, 'overdue'])
                ->middleware('authorize:payments.schedule.view')
                ->name('overdue');
            Route::get('/documents/{id}', [PaymentDocumentController::class, 'show'])
                ->middleware('authorize:payments.schedule.view')
                ->name('documents.show');
        });

        Route::get('/counterparty-accounts/{organizationId}', [CounterpartyAccountController::class, 'show'])
            ->middleware('authorize:payments.counterparty_account.view')
            ->name('counterparty_accounts.show');

        Route::post('/reconciliation', [ReconciliationController::class, 'store'])
            ->middleware('authorize:payments.reconciliation.perform')
            ->name('reconciliation.store');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/financial', [ReportController::class, 'financial'])
                ->middleware('authorize:payments.reports.view')
                ->name('financial');
            Route::post('/export', [ReportController::class, 'export'])
                ->middleware('authorize:payments.reports.export')
                ->name('export');
            Route::get('/cash-flow', [PaymentReportsController::class, 'cashFlow'])
                ->middleware('authorize:payments.reports.view')
                ->name('cash_flow');
            Route::get('/aging-analysis', [PaymentReportsController::class, 'agingAnalysis'])
                ->middleware('authorize:payments.reports.view')
                ->name('aging_analysis');
            Route::get('/critical-contractors', [PaymentReportsController::class, 'criticalContractors'])
                ->middleware('authorize:payments.reports.view')
                ->name('critical_contractors');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingsController::class, 'show'])
                ->middleware('authorize:payments.settings.view')
                ->name('show');
            Route::put('/', [SettingsController::class, 'update'])
                ->middleware('authorize:payments.settings.manage')
                ->name('update');
        });

        Route::prefix('documents')->name('documents.')->group(function () {
            Route::post('/bulk', [PaymentDocumentController::class, 'bulkAction'])
                ->middleware('authorize:payments.invoice.edit')
                ->name('bulk_action');

            Route::get('/calendar', [PaymentCalendarController::class, 'index'])
                ->middleware('authorize:payments.schedule.view')
                ->name('calendar.index');
            Route::post('/{id}/reschedule', [PaymentCalendarController::class, 'reschedule'])
                ->middleware('authorize:payments.schedule.edit')
                ->name('calendar.reschedule');

            Route::get('/', [PaymentDocumentController::class, 'index'])
                ->middleware('authorize:payments.invoice.view')
                ->name('index');
            Route::post('/', [PaymentDocumentController::class, 'store'])
                ->middleware('authorize:payments.invoice.create')
                ->name('store');
            Route::get('/overdue', [PaymentDocumentController::class, 'overdue'])
                ->middleware('authorize:payments.invoice.view')
                ->name('overdue');
            Route::get('/upcoming', [PaymentDocumentController::class, 'upcoming'])
                ->middleware('authorize:payments.invoice.view')
                ->name('upcoming');
            Route::get('/statistics', [PaymentDocumentController::class, 'statistics'])
                ->middleware('authorize:payments.invoice.view')
                ->name('statistics');
            Route::get('/{id}', [PaymentDocumentController::class, 'show'])
                ->middleware('authorize:payments.invoice.view')
                ->name('show');
            Route::put('/{id}', [PaymentDocumentController::class, 'update'])
                ->middleware('authorize:payments.invoice.edit')
                ->name('update');
            Route::delete('/{id}', [PaymentDocumentController::class, 'destroy'])
                ->middleware('authorize:payments.invoice.delete')
                ->name('destroy');
            Route::get('/{id}/print-order', [PaymentDocumentController::class, 'printOrder'])
                ->middleware('authorize:payments.invoice.export')
                ->name('print_order');
            Route::post('/{id}/submit', [PaymentDocumentController::class, 'submit'])
                ->middleware('authorize:payments.invoice.issue')
                ->name('submit');
            Route::post('/{id}/schedule', [PaymentDocumentController::class, 'schedule'])
                ->middleware('authorize:payments.schedule.create')
                ->name('schedule');
            Route::post('/{id}/register-payment', [PaymentDocumentController::class, 'registerPayment'])
                ->middleware('authorize:payments.transaction.register')
                ->name('register_payment');
            Route::post('/{id}/cancel', [PaymentDocumentController::class, 'cancel'])
                ->middleware('authorize:payments.invoice.cancel')
                ->name('cancel');
            Route::post('/generate-purpose', [PaymentDocumentController::class, 'generatePurpose'])
                ->middleware('authorize:payments.invoice.create')
                ->name('generate_purpose');
        });

        Route::prefix('approvals')->name('approvals.')->group(function () {
            Route::get('/my', [PaymentApprovalController::class, 'myApprovals'])
                ->middleware('authorize:payments.transaction.view')
                ->name('my');
            Route::post('/documents/{documentId}/approve', [PaymentApprovalController::class, 'approve'])
                ->middleware('authorize:payments.transaction.approve')
                ->name('approve');
            Route::post('/documents/{documentId}/reject', [PaymentApprovalController::class, 'reject'])
                ->middleware('authorize:payments.transaction.reject')
                ->name('reject');
            Route::get('/documents/{documentId}/history', [PaymentApprovalController::class, 'history'])
                ->middleware('authorize:payments.transaction.view')
                ->name('history');
            Route::get('/documents/{documentId}/status', [PaymentApprovalController::class, 'status'])
                ->middleware('authorize:payments.transaction.view')
                ->name('status');
            Route::post('/documents/{documentId}/send-reminders', [PaymentApprovalController::class, 'sendReminders'])
                ->middleware('authorize:payments.settings.manage')
                ->name('send_reminders');
        });

        Route::prefix('requests')->name('requests.')->group(function () {
            Route::get('/incoming', [PaymentRequestController::class, 'incoming'])
                ->middleware('authorize:payments.invoice.view')
                ->name('incoming');
            Route::get('/outgoing', [PaymentRequestController::class, 'outgoing'])
                ->middleware('authorize:payments.invoice.view')
                ->name('outgoing');
            Route::post('/', [PaymentRequestController::class, 'store'])
                ->middleware('authorize:payments.invoice.create')
                ->name('store');
            Route::post('/{id}/accept', [PaymentRequestController::class, 'accept'])
                ->middleware('authorize:payments.transaction.approve')
                ->name('accept');
            Route::post('/{id}/reject', [PaymentRequestController::class, 'reject'])
                ->middleware('authorize:payments.transaction.reject')
                ->name('reject');
            Route::get('/contractors/{contractorId}', [PaymentRequestController::class, 'fromContractor'])
                ->middleware('authorize:payments.invoice.view')
                ->name('from_contractor');
            Route::get('/statistics', [PaymentRequestController::class, 'statistics'])
                ->middleware('authorize:payments.invoice.view')
                ->name('statistics');
        });

        Route::prefix('incoming')->name('incoming.')->group(function () {
            Route::get('/documents', [PaymentRecipientController::class, 'index'])
                ->middleware('authorize:payments.invoice.view')
                ->name('documents.index');
            Route::get('/documents/{documentId}', [PaymentRecipientController::class, 'show'])
                ->middleware('authorize:payments.invoice.view')
                ->name('documents.show');
            Route::post('/documents/{documentId}/view', [PaymentRecipientController::class, 'markAsViewed'])
                ->middleware('authorize:payments.invoice.view')
                ->name('documents.view');
            Route::post('/documents/{documentId}/confirm', [PaymentRecipientController::class, 'confirmReceipt'])
                ->middleware('authorize:payments.transaction.approve')
                ->name('documents.confirm');
            Route::get('/statistics', [PaymentRecipientController::class, 'statistics'])
                ->middleware('authorize:payments.invoice.view')
                ->name('statistics');
        });

        Route::prefix('offsets')->name('offsets.')->group(function () {
            Route::get('/opportunities', [OffsetController::class, 'opportunities'])
                ->middleware('authorize:payments.reconciliation.view')
                ->name('opportunities');
            Route::post('/perform', [OffsetController::class, 'perform'])
                ->middleware('authorize:payments.reconciliation.perform')
                ->name('perform');
            Route::post('/auto', [OffsetController::class, 'auto'])
                ->middleware('authorize:payments.reconciliation.perform')
                ->name('auto');
        });

        Route::prefix('export')->name('export.')->group(function () {
            Route::post('/excel', [ExportController::class, 'excel'])
                ->middleware('authorize:payments.reports.export')
                ->name('excel');
            Route::post('/pdf/{documentId}', [ExportController::class, 'pdf'])
                ->middleware('authorize:payments.reports.export')
                ->name('pdf');
            Route::post('/1c', [ExportController::class, 'onec'])
                ->middleware('authorize:payments.reports.export')
                ->name('1c');
        });
    });

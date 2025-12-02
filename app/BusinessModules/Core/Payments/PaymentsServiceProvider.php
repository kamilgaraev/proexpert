<?php

namespace App\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Jobs\ProcessOverdueInvoicesJob;
use App\BusinessModules\Core\Payments\Services\CounterpartyAccountService;
use App\BusinessModules\Core\Payments\Services\PaymentAccessControl;
use App\BusinessModules\Core\Payments\Services\PaymentScheduleService;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentStateMachine;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use App\BusinessModules\Core\Payments\Services\PaymentValidationService;
use App\BusinessModules\Core\Payments\Services\PaymentRequestService;
use App\BusinessModules\Core\Payments\Services\PaymentScheduleGenerator;
use App\BusinessModules\Core\Payments\Services\PaymentAuditService;
use App\BusinessModules\Core\Payments\Services\PaymentExportService;
use App\BusinessModules\Core\Payments\Services\Reports\CashFlowReportService;
use App\BusinessModules\Core\Payments\Services\Reports\AgingAnalysisReportService;
use App\BusinessModules\Core\Payments\Services\OffsetService;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Observers\PaymentDocumentObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;

use App\BusinessModules\Core\Payments\Services\Export\PaymentOrderPdfService;
use App\BusinessModules\Core\Payments\Services\Import\BankStatementImportService;
use App\BusinessModules\Core\Payments\Services\Integrations\BudgetControlService;
use App\BusinessModules\Core\Payments\Services\PaymentPurposeGenerator;

class PaymentsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Singleton сервисы (один экземпляр на запрос)
        $this->app->singleton(PaymentsModule::class);
        $this->app->singleton(PaymentAccessControl::class);
        $this->app->singleton(CounterpartyAccountService::class);
        $this->app->singleton(PaymentDocumentStateMachine::class);
        $this->app->singleton(ApprovalWorkflowService::class);
        $this->app->singleton(PaymentValidationService::class);
        $this->app->singleton(PaymentAuditService::class);
        
        // New services
        $this->app->singleton(BudgetControlService::class);
        $this->app->singleton(PaymentPurposeGenerator::class);
        
        // Bind сервисы (новый экземпляр при каждом resolve)
        $this->app->bind(PaymentTransactionService::class);
        $this->app->bind(PaymentScheduleService::class);
        $this->app->bind(PaymentDocumentService::class);
        $this->app->bind(PaymentRequestService::class);
        $this->app->bind(PaymentScheduleGenerator::class);
        $this->app->bind(PaymentExportService::class);
        $this->app->bind(CashFlowReportService::class);
        $this->app->bind(AgingAnalysisReportService::class);
        $this->app->bind(OffsetService::class);
        
        // New services
        $this->app->bind(PaymentOrderPdfService::class);
        $this->app->bind(BankStatementImportService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Загрузка миграций
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Загрузка маршрутов
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        // Регистрация event listeners
        $this->registerEventListeners();
        
        // Регистрация observers
        $this->registerObservers();
        
        // Регистрация команд для scheduler
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\BusinessModules\Core\Payments\Console\Commands\ResetInvoiceNumberSequences::class,
                \App\BusinessModules\Core\Payments\Console\Commands\MigrateInvoicesToPaymentDocuments::class,
                \App\BusinessModules\Core\Payments\Console\Commands\RestoreInvoiceableRelations::class,
                \App\BusinessModules\Core\Payments\Console\Commands\SendDailyPaymentDigest::class,
            ]);
        }

        // Регистрация задач в scheduler (в app/Console/Kernel.php нужно добавить):
        // use App\BusinessModules\Core\Payments\Jobs\ProcessOverduePaymentsJob;
        // use App\BusinessModules\Core\Payments\Jobs\SendPaymentRemindersJob;
        // use App\BusinessModules\Core\Payments\Jobs\SendUpcomingPaymentNotificationsJob;
        //
        // $schedule->job(new ProcessOverduePaymentsJob())->daily();
        // $schedule->job(new SendPaymentRemindersJob())->daily();
        // $schedule->job(new SendUpcomingPaymentNotificationsJob())->dailyAt('09:00');
        
        Log::info('PaymentsServiceProvider booted');
    }
    
    /**
     * Регистрация event listeners
     */
    protected function registerEventListeners(): void
    {
        Event::subscribe(\App\BusinessModules\Core\Payments\Listeners\SendPaymentNotifications::class);
        
        // Автосоздание счетов из актов (проверит настройку внутри)
        // Нужно зарегистрировать слушание события создания/обновления актов
        // Если у вас есть события ActCreated или ActSigned, добавьте их:
        // Event::listen(ActSigned::class, [AutoCreateInvoiceFromAct::class, 'handle']);
    }
    
    /**
     * Регистрация observers
     */
    protected function registerObservers(): void
    {
        PaymentDocument::observe(PaymentDocumentObserver::class);
    }
}


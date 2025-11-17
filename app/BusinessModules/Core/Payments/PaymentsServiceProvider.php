<?php

namespace App\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Jobs\ProcessOverdueInvoicesJob;
use App\BusinessModules\Core\Payments\Services\CounterpartyAccountService;
use App\BusinessModules\Core\Payments\Services\InvoiceService;
use App\BusinessModules\Core\Payments\Services\PaymentAccessControl;
use App\BusinessModules\Core\Payments\Services\PaymentScheduleService;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionService;
use Illuminate\Support\ServiceProvider;

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
        
        // Bind сервисы (новый экземпляр при каждом resolve)
        $this->app->bind(InvoiceService::class);
        $this->app->bind(PaymentTransactionService::class);
        $this->app->bind(PaymentScheduleService::class);
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
        
        // Регистрация команд для scheduler
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Здесь можно добавить Artisan команды если нужно
            ]);
        }

        // Регистрация задач в scheduler (в app/Console/Kernel.php нужно добавить)
        // $schedule->job(ProcessOverdueInvoicesJob::class)->hourly();
        
        \Log::info('PaymentsServiceProvider booted');
    }
}


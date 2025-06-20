<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Services\Billing\MockPaymentGateway; // Наша заглушка
// use App\Services\Billing\YooKassaGateway; // Реальная интеграция в будущем
use App\Interfaces\Billing\SubscriptionPlanServiceInterface;
use App\Services\Billing\SubscriptionPlanService;
use App\Interfaces\Billing\UserSubscriptionServiceInterface;
use App\Services\Billing\UserSubscriptionService;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Services\Billing\BalanceService;
use App\Interfaces\Billing\SubscriptionLimitsServiceInterface;
use App\Services\Billing\SubscriptionLimitsService;

class BillingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Привязываем PaymentGatewayInterface к MockPaymentGateway
        // Когда понадобится реальная интеграция, здесь можно будет легко переключить на YooKassaGateway
        $this->app->singleton(PaymentGatewayInterface::class, function ($app) {
            // if (config('services.payment_gateway.driver') === 'yookassa') {
            //     return new YooKassaGateway(config('services.yookassa.shop_id'), config('services.yookassa.secret_key'));
            // }
            return new MockPaymentGateway();
        });

        $this->app->singleton(SubscriptionPlanServiceInterface::class, SubscriptionPlanService::class);
        $this->app->singleton(UserSubscriptionServiceInterface::class, UserSubscriptionService::class);
        $this->app->singleton(BalanceServiceInterface::class, BalanceService::class);
        $this->app->singleton(SubscriptionLimitsServiceInterface::class, SubscriptionLimitsService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
} 
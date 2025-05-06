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
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
} 
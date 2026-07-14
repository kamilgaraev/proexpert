<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Interfaces\Billing\SubscriptionLimitsServiceInterface;
use App\Interfaces\Billing\SubscriptionPlanServiceInterface;
use App\Services\Billing\BalanceService;
use App\Services\Billing\CommercialWebhookService;
use App\Services\Billing\SubscriptionLimitsService;
use App\Services\Billing\SubscriptionPlanService;
use App\Services\Billing\YooKassaPaymentGateway;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayInterface::class, YooKassaPaymentGateway::class);
        $this->app->singleton(CommercialWebhookProcessor::class, CommercialWebhookService::class);
        $this->app->singleton(SubscriptionPlanServiceInterface::class, SubscriptionPlanService::class);
        $this->app->singleton(BalanceServiceInterface::class, BalanceService::class);
        $this->app->singleton(SubscriptionLimitsServiceInterface::class, SubscriptionLimitsService::class);
    }
}

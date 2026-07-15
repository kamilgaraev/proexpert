<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\Enums\Billing\PaymentProviderMode;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Services\Billing\BalanceService;
use App\Services\Billing\CommercialWebhookService;
use App\Services\Billing\MockPaymentGateway;
use App\Services\Billing\YooKassaPaymentGateway;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayInterface::class, static function ($app): PaymentGatewayInterface {
            return match (PaymentProviderMode::configured()) {
                PaymentProviderMode::Mock => $app->make(MockPaymentGateway::class),
                PaymentProviderMode::YooKassaTest,
                PaymentProviderMode::YooKassaLive => $app->make(YooKassaPaymentGateway::class),
            };
        });
        $this->app->singleton(CommercialWebhookProcessor::class, CommercialWebhookService::class);
        $this->app->singleton(BalanceServiceInterface::class, BalanceService::class);
    }
}

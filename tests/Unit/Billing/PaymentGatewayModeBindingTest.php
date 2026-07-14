<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Exceptions\Billing\PaymentGatewayConfigurationException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Services\Billing\MockPaymentGateway;
use App\Services\Billing\YooKassaPaymentGateway;
use Tests\TestCase;

final class PaymentGatewayModeBindingTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_mock_mode_binds_deterministic_in_process_gateway(): void
    {
        config()->set('app.env', 'local');
        config()->set('services.yookassa.mode', 'mock');
        $this->app->forgetInstance(PaymentGatewayInterface::class);

        $this->assertInstanceOf(MockPaymentGateway::class, app(PaymentGatewayInterface::class));
    }

    public function test_test_and_live_modes_bind_http_gateway(): void
    {
        foreach (['yookassa_test', 'yookassa_live'] as $mode) {
            config()->set('services.yookassa.mode', $mode);
            $this->app->forgetInstance(PaymentGatewayInterface::class);

            $this->assertInstanceOf(YooKassaPaymentGateway::class, app(PaymentGatewayInterface::class));
        }
    }

    public function test_unknown_or_missing_mode_fails_closed(): void
    {
        foreach (['', 'test', 'unexpected'] as $mode) {
            config()->set('services.yookassa.mode', $mode);
            $this->app->forgetInstance(PaymentGatewayInterface::class);

            try {
                app(PaymentGatewayInterface::class);
                $this->fail("Mode {$mode} must fail closed.");
            } catch (PaymentGatewayConfigurationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_production_forbids_mock_mode(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('services.yookassa.mode', 'mock');
        $this->app->forgetInstance(PaymentGatewayInterface::class);

        $this->expectException(PaymentGatewayConfigurationException::class);

        app(PaymentGatewayInterface::class);
    }
}

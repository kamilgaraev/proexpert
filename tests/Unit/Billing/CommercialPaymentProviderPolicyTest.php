<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Exceptions\Billing\PaymentGatewayConfigurationException;
use App\Services\Billing\CommercialPaymentProviderPolicy;
use Tests\TestCase;

final class CommercialPaymentProviderPolicyTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_test_mode_empty_allowlist_denies_all_organizations(): void
    {
        config()->set('services.yookassa.mode', 'yookassa_test');
        config()->set('services.yookassa.test_organization_ids', []);

        $this->expectException(PaymentGatewayConfigurationException::class);

        app(CommercialPaymentProviderPolicy::class)->assertCanCharge(42);
    }

    public function test_test_mode_rejects_nonmatching_and_accepts_matching_organization(): void
    {
        config()->set('services.yookassa.mode', 'yookassa_test');
        config()->set('services.yookassa.test_organization_ids', [7, 42]);
        $policy = app(CommercialPaymentProviderPolicy::class);

        try {
            $policy->assertCanCharge(41);
            $this->fail('Nonmatching organization must be rejected.');
        } catch (PaymentGatewayConfigurationException $exception) {
            $this->assertStringNotContainsString('42', $exception->getMessage());
            $this->assertStringNotContainsString('7', $exception->getMessage());
        }

        $policy->assertCanCharge(42);
        $this->assertTrue(true);
    }

    public function test_live_mode_requires_explicit_launch_and_receipt_readiness(): void
    {
        config()->set('services.yookassa.mode', 'yookassa_live');
        config()->set('services.yookassa.live', [
            'enabled' => false,
            'legal_entity_confirmed' => false,
            'contract_confirmed' => false,
            'receipt_settings_confirmed' => false,
        ]);
        config()->set('services.yookassa.receipt', ['enabled' => false]);

        $this->expectException(PaymentGatewayConfigurationException::class);

        app(CommercialPaymentProviderPolicy::class)->assertCanCreatePayment();
    }
}

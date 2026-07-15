<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\PaymentProviderMode;
use App\Exceptions\Billing\PaymentGatewayConfigurationException;

use function trans_message;

final class CommercialPaymentProviderPolicy
{
    public function assertCanCharge(int $organizationId): void
    {
        if (PaymentProviderMode::configured() !== PaymentProviderMode::YooKassaTest) {
            return;
        }

        $allowedIds = array_map('intval', (array) config('services.yookassa.test_organization_ids', []));
        if ($allowedIds === []) {
            return;
        }

        if (! in_array($organizationId, $allowedIds, true)) {
            throw new PaymentGatewayConfigurationException(trans_message('billing.provider.test_unavailable'));
        }
    }

    public function assertCanCreatePayment(): void
    {
        if (PaymentProviderMode::configured() !== PaymentProviderMode::YooKassaLive) {
            return;
        }

        $live = (array) config('services.yookassa.live', []);
        $ready = ($live['enabled'] ?? false) === true
            && ($live['legal_entity_confirmed'] ?? false) === true
            && ($live['contract_confirmed'] ?? false) === true
            && ($live['receipt_settings_confirmed'] ?? false) === true;

        if (! $ready || $this->receiptConfiguration() === null) {
            throw $this->configurationException();
        }
    }

    public function assertCanCreateRefund(int $organizationId): void
    {
        if (PaymentProviderMode::configured() === PaymentProviderMode::Mock) {
            throw $this->configurationException();
        }

        $this->assertCanCharge($organizationId);
        $this->assertCanCreatePayment();
    }

    public function receipt(?string $customerEmail, string $description, int $amountMinor, string $currency): ?array
    {
        $this->assertCanCreatePayment();
        $configuration = $this->receiptConfiguration();
        if ($configuration === null) {
            return null;
        }

        $email = trim((string) $customerEmail);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->configurationException();
        }

        return [
            'customer' => ['email' => $email],
            'items' => [[
                'description' => $description,
                'quantity' => '1.000',
                'amount' => [
                    'value' => sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100),
                    'currency' => $currency,
                ],
                'vat_code' => $configuration['vat_code'],
                'payment_mode' => $configuration['payment_mode'],
                'payment_subject' => $configuration['payment_subject'],
            ]],
        ];
    }

    private function receiptConfiguration(): ?array
    {
        $receipt = (array) config('services.yookassa.receipt', []);
        if (($receipt['enabled'] ?? false) !== true) {
            return null;
        }

        $vatCode = filter_var($receipt['vat_code'] ?? null, FILTER_VALIDATE_INT);
        $paymentMode = trim((string) ($receipt['payment_mode'] ?? ''));
        $paymentSubject = trim((string) ($receipt['payment_subject'] ?? ''));
        if ($vatCode === false || $vatCode < 1 || $paymentMode === '' || $paymentSubject === '') {
            throw $this->configurationException();
        }

        return ['vat_code' => $vatCode, 'payment_mode' => $paymentMode, 'payment_subject' => $paymentSubject];
    }

    private function configurationException(): PaymentGatewayConfigurationException
    {
        return new PaymentGatewayConfigurationException(trans_message('billing.provider.configuration_error'));
    }
}

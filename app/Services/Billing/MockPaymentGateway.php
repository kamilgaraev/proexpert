<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateRefundData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\Interfaces\Billing\PaymentGatewayInterface;
use RuntimeException;

final class MockPaymentGateway implements PaymentGatewayInterface
{
    /** @var array<string, PaymentGatewayResult> */
    private array $payments = [];

    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        return $this->payments[$payment->idempotenceKey] ??= $this->paymentResult(
            $payment->idempotenceKey,
            $payment->amountMinor,
            $payment->currency,
            $payment->metadata,
            $payment->savePaymentMethod,
            true,
        );
    }

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult
    {
        return $this->payments[$payment->idempotenceKey] ??= $this->paymentResult(
            $payment->idempotenceKey,
            $payment->amountMinor,
            $payment->currency,
            $payment->metadata,
            true,
            false,
        );
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        foreach ($this->payments as $payment) {
            if ($payment->id === $paymentId) {
                return $payment;
            }
        }

        throw new RuntimeException('Mock payment was not found.');
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        throw new RuntimeException('Mock refund was not found.');
    }

    public function createRefund(CreateRefundData $refund): RefundGatewayResult
    {
        throw new RuntimeException('Refunds are unavailable in mock mode.');
    }

    private function paymentResult(
        string $key,
        int $amountMinor,
        string $currency,
        array $metadata,
        bool $saved,
        bool $redirect,
    ): PaymentGatewayResult {
        $id = 'mock-payment-'.substr(hash('sha256', $key), 0, 24);

        return new PaymentGatewayResult(
            id: $id,
            status: 'pending',
            confirmationUrl: $redirect ? 'https://mock.invalid/payments/'.$id : null,
            paymentMethodId: $saved ? 'mock-method-'.substr(hash('sha256', $key), 0, 20) : null,
            paymentMethodSaved: $saved,
            safeResponse: ['id' => $id, 'status' => 'pending', 'test' => true],
            paid: false,
            test: true,
            amountMinor: $amountMinor,
            currency: $currency,
            metadata: $metadata,
        );
    }
}

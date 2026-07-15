<?php

declare(strict_types=1);

namespace App\Interfaces\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateRefundData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;

interface PaymentGatewayInterface
{
    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult;

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult;

    public function getPayment(string $paymentId): PaymentGatewayResult;

    public function createRefund(CreateRefundData $refund): RefundGatewayResult;

    public function getRefund(string $refundId): RefundGatewayResult;
}

<?php

declare(strict_types=1);

namespace App\Interfaces\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;

interface PaymentGatewayInterface
{
    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult;

    public function getPayment(string $paymentId): PaymentGatewayResult;
}

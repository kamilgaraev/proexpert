<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;

interface CommercialWebhookProcessor
{
    public function process(YooKassaWebhookNotification $notification, string $sourceIp): string;

    public function processAuthoritativePayment(
        YooKassaWebhookNotification $notification,
        string $sourceIp,
        PaymentGatewayResult $payment,
    ): string;

    public function processAuthoritativeRefund(
        YooKassaWebhookNotification $notification,
        string $sourceIp,
        RefundGatewayResult $refund,
        PaymentGatewayResult $payment,
    ): string;
}

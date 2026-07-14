<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

use App\DataTransferObjects\Billing\YooKassaWebhookNotification;

interface CommercialWebhookProcessor
{
    public function process(YooKassaWebhookNotification $notification, string $sourceIp): string;
}

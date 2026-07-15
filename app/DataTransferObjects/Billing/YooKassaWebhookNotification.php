<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

final readonly class YooKassaWebhookNotification
{
    public function __construct(
        public string $event,
        public string $objectId,
        public string $objectState,
        public array $safePayload,
    ) {}
}

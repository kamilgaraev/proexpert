<?php

namespace App\DataTransferObjects\Billing;

class PaymentGatewaySubscriptionResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $gatewaySubscriptionId, // ID подписки в шлюзе
        public readonly string $status, // Статус подписки в терминах шлюза
        public readonly ?string $message = null,
        public readonly ?array $gatewaySpecificResponse = null // Полный ответ от шлюза для отладки
    ) {}
} 
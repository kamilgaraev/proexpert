<?php

namespace App\DataTransferObjects\Billing;

class PaymentGatewayChargeResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $chargeId, // ID операции в шлюзе
        public readonly string $status, // Статус в терминах шлюза
        public readonly ?string $message = null,
        public readonly ?string $redirectUrl = null, // URL для редиректа пользователя на оплату
        public readonly ?array $gatewaySpecificResponse = null // Полный ответ от шлюза для отладки
    ) {}
} 
<?php

namespace App\DataTransferObjects\Billing;

use App\Models\UserSubscription;
use App\Models\Payment; // Если смена плана генерирует платеж

class SwitchPlanResult
{
    public function __construct(
        public readonly UserSubscription $newSubscription,
        public readonly ?Payment $payment = null, // Платеж за смену плана (если был)
        public readonly bool $requiresAction = false, // Требуется ли доп. действие от пользователя (например, 3DS)
        public readonly ?string $redirectUrl = null, // URL для действия
        public readonly ?string $message = null // Сообщение для пользователя
    ) {}
} 
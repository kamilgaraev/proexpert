<?php

namespace App\Interfaces\Billing;

use App\Models\User;
use App\Models\SubscriptionPlan;
use App\DataTransferObjects\Billing\PaymentGatewayChargeResponse;
use App\DataTransferObjects\Billing\PaymentGatewaySubscriptionResponse;

interface PaymentGatewayInterface
{
    /**
     * Создает разовый платеж через шлюз.
     *
     * @param User $user Пользователь, инициирующий платеж.
     * @param int $amount Сумма в минорных единицах (копейках).
     * @param string $currency Валюта (RUB, USD и т.д.).
     * @param string $description Описание платежа.
     * @param string|null $paymentMethodId Токен/ID метода оплаты (если применимо и сохранен).
     * @param array $metadata Дополнительные данные для шлюза.
     * @param string|null $returnUrl URL для возврата пользователя после оплаты.
     * @return PaymentGatewayChargeResponse
     */
    public function createCharge(
        User $user,
        int $amount,
        string $currency,
        string $description,
        ?string $paymentMethodId = null,
        array $metadata = [],
        ?string $returnUrl = null
    ): PaymentGatewayChargeResponse;

    /**
     * Получает детализацию платежа из шлюза.
     *
     * @param string $chargeId ID платежа в шлюзе.
     * @return PaymentGatewayChargeResponse
     */
    public function getChargeDetails(string $chargeId): PaymentGatewayChargeResponse;

    /**
     * Создает подписку в платежном шлюзе.
     *
     * @param User $user
     * @param SubscriptionPlan $plan
     * @param string|null $paymentMethodId
     * @param array $metadata
     * @return PaymentGatewaySubscriptionResponse
     */
    public function createSubscription(
        User $user,
        SubscriptionPlan $plan,
        ?string $paymentMethodId = null,
        array $metadata = []
    ): PaymentGatewaySubscriptionResponse;

    /**
     * Отменяет подписку в платежном шлюзе.
     *
     * @param string $gatewaySubscriptionId ID подписки в шлюзе.
     * @param bool $atPeriodEnd True, если отменить в конце оплаченного периода, False - немедленно.
     * @return bool Успешность операции.
     */
    public function cancelGatewaySubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): bool;

    // Здесь могут быть другие методы: refund, updatePaymentMethod и т.д.
} 
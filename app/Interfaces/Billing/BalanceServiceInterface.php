<?php

namespace App\Interfaces\Billing;

use App\Models\Organization;
use App\Models\OrganizationBalance;
use App\Models\Payment;
use App\Models\OrganizationSubscription;

interface BalanceServiceInterface
{
    /**
     * Получить или создать баланс для организации.
     *
     * @param Organization $organization
     * @return OrganizationBalance
     */
    public function getOrCreateOrganizationBalance(Organization $organization): OrganizationBalance;

    /**
     * Пополнить баланс организации.
     *
     * @param Organization $organization
     * @param int $amount Сумма в минорных единицах (копейках).
     * @param string $description Описание пополнения.
     * @param Payment|null $payment Связанный платеж (если пополнение через шлюз).
     * @param array $meta Дополнительные метаданные.
     * @return OrganizationBalance Обновленный баланс.
     * @throws \App\Exceptions\Billing\BalanceException
     */
    public function creditBalance(
        Organization $organization,
        int $amount,
        string $description,
        ?Payment $payment = null,
        array $meta = []
    ): OrganizationBalance;

    /**
     * Списать средства с баланса организации.
     *
     * @param Organization $organization
     * @param int $amount Сумма в минорных единицах (копейках).
     * @param string $description Описание списания.
     * @param OrganizationSubscription|null $subscription Связанная подписка (если списание за подписку).
     * @param array $meta Дополнительные метаданные.
     * @return OrganizationBalance Обновленный баланс.
     * @throws \App\Exceptions\Billing\InsufficientBalanceException
     * @throws \App\Exceptions\Billing\BalanceException
     */
    public function debitBalance(
        Organization $organization,
        int $amount,
        string $description,
        ?OrganizationSubscription $subscription = null,
        array $meta = []
    ): OrganizationBalance;

    /**
     * Проверяет, достаточно ли средств на балансе для списания.
     *
     * @param Organization $organization
     * @param int $amount Сумма в минорных единицах.
     * @return bool
     */
    public function hasSufficientBalance(Organization $organization, int $amount): bool;
} 
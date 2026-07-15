<?php

namespace App\Interfaces\Billing;

use App\Models\Organization;
use App\Models\OrganizationBalance;
use App\Models\Payment;

interface BalanceServiceInterface
{
    /**
     * Получить или создать баланс для организации.
     */
    public function getOrCreateOrganizationBalance(Organization $organization): OrganizationBalance;

    /**
     * Пополнить баланс организации.
     *
     * @param  int  $amount  Сумма в минорных единицах (копейках).
     * @param  string  $description  Описание пополнения.
     * @param  Payment|null  $payment  Связанный платеж (если пополнение через шлюз).
     * @param  array  $meta  Дополнительные метаданные.
     * @return OrganizationBalance Обновленный баланс.
     *
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
     * @param  int  $amount  Сумма в минорных единицах (копейках).
     * @param  string  $description  Описание списания.
     * @param  array  $meta  Дополнительные метаданные.
     * @return OrganizationBalance Обновленный баланс.
     *
     * @throws \App\Exceptions\Billing\InsufficientBalanceException
     * @throws \App\Exceptions\Billing\BalanceException
     */
    public function debitBalance(
        Organization $organization,
        int $amount,
        string $description,
        array $meta = []
    ): OrganizationBalance;

    /**
     * Проверяет, достаточно ли средств на балансе для списания.
     *
     * @param  int  $amount  Сумма в минорных единицах.
     */
    public function hasSufficientBalance(Organization $organization, int $amount): bool;
}

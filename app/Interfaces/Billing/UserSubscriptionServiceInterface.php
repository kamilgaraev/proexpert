<?php

namespace App\Interfaces\Billing;

use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\DataTransferObjects\Billing\SwitchPlanResult;
use App\Exceptions\Billing\SubscriptionException;
use Carbon\Carbon;

interface UserSubscriptionServiceInterface
{
    /**
     * Подписывает пользователя на указанный тарифный план.
     * Может включать создание пробного периода или немедленную оплату.
     *
     * @param User $user
     * @param SubscriptionPlan $plan
     * @param string|null $paymentMethodToken Токен метода оплаты для немедленного списания.
     * @param array $gatewayOptions Дополнительные опции для платежного шлюза.
     * @return UserSubscription Вновь созданная или обновленная подписка.
     * @throws SubscriptionException
     */
    public function subscribeUserToPlan(
        User $user,
        SubscriptionPlan $plan,
        ?string $paymentMethodToken = null,
        array $gatewayOptions = []
    ): UserSubscription;

    /**
     * Переводит пользователя на новый тарифный план.
     * Обрабатывает пропорциональное начисление/списание, если это применимо.
     *
     * @param UserSubscription $currentSubscription Текущая активная подписка.
     * @param SubscriptionPlan $newPlan Новый тарифный план.
     * @param string|null $paymentMethodToken Для оплаты разницы, если требуется.
     * @return SwitchPlanResult Результат смены плана (включая новую подписку и инфо о платеже).
     * @throws SubscriptionException
     */
    public function switchUserPlan(
        UserSubscription $currentSubscription,
        SubscriptionPlan $newPlan,
        ?string $paymentMethodToken = null
    ): SwitchPlanResult; 

    /**
     * Отменяет подписку пользователя.
     *
     * @param UserSubscription $subscription Подписка для отмены.
     * @param bool $atPeriodEnd Если true, подписка останется активной до конца оплаченного периода.
     *                         Если false, отменяется немедленно (если это поддерживается).
     * @return UserSubscription Обновленная подписка.
     * @throws SubscriptionException
     */
    public function cancelSubscription(UserSubscription $subscription, bool $atPeriodEnd = true): UserSubscription;

    /**
     * Возобновляет ранее отмененную подписку, если это возможно.
     *
     * @param UserSubscription $subscription
     * @return UserSubscription
     * @throws SubscriptionException
     */
    public function resumeSubscription(UserSubscription $subscription): UserSubscription;

    /**
     * Получает текущую активную или пробную подписку пользователя.
     *
     * @param User $user
     * @return UserSubscription|null
     */
    public function getUserCurrentValidSubscription(User $user): ?UserSubscription;

    /**
     * Проверяет, есть ли у пользователя действующая подписка (активная или триал).
     *
     * @param User $user
     * @return bool
     */
    public function userHasActiveSubscription(User $user): bool;

    /**
     * Проверяет лимиты пользователя согласно его текущей подписке.
     *
     * @param User $user
     * @param string $limitKey Ключ лимита (например, 'max_foremen', 'max_projects').
     * @param int $valueToConsume Количество потребляемого ресурса (по умолчанию 1 для проверки возможности).
     * @return bool True, если лимит не превышен, false в противном случае.
     */
    public function checkUserLimit(User $user, string $limitKey, int $valueToConsume = 1): bool;

    /**
     * Обрабатывает событие от платежного шлюза (веб-хук).
     *
     * @param string $eventType Тип события (например, 'invoice.payment_succeeded').
     * @param array $payload Данные события.
     * @return void
     * @throws SubscriptionException
     */
    public function handleWebhook(string $eventType, array $payload): void;

    /**
     * Пытается обработать просроченный платеж для подписки.
     *
     * @param UserSubscription $subscription
     * @return bool true если платеж успешно проведен, false иначе
     */
    public function processPastDuePayment(UserSubscription $subscription): bool;

    /**
     * Синхронизирует статус подписки с платежным шлюзом.
     * Полезно, если веб-хук не дошел или для периодической проверки.
     *
     * @param UserSubscription $subscription
     * @return UserSubscription
     */
    public function syncSubscriptionStatus(UserSubscription $subscription): UserSubscription;

} 
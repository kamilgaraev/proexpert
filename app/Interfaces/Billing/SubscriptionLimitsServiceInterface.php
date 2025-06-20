<?php

namespace App\Interfaces\Billing;

use App\Models\User;

interface SubscriptionLimitsServiceInterface
{
    /**
     * Получить данные о лимитах пользователя
     */
    public function getUserLimitsData(User $user): array;

    /**
     * Получить текущее использование ресурсов пользователем
     */
    public function getCurrentUsage(User $user): array;

    /**
     * Очистить кэш использования ресурсов пользователя
     */
    public function clearUserUsageCache(User $user): void;
} 
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

    /**
     * Проверить, может ли пользователь создать нового прораба
     */
    public function canCreateForeman(User $user): bool;

    /**
     * Проверить, может ли пользователь создать новый проект
     */
    public function canCreateProject(User $user): bool;

    /**
     * Проверить, может ли пользователь создать нового пользователя
     */
    public function canCreateUser(User $user): bool;

    /**
     * Проверить, может ли пользователь создать приглашение подрядчика
     */
    public function canCreateContractorInvitation(User $user): bool;
} 
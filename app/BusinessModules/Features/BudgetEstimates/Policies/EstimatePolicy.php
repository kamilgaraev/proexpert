<?php

namespace App\BusinessModules\Features\BudgetEstimates\Policies;

use App\Models\User;
use App\Models\Estimate;

/**
 * ИСПРАВЛЕННАЯ Policy с правильными permissions
 * 
 * ВАЖНО: Используем префикс 'budget-estimates' вместо 'estimates'
 * для соответствия slug модуля
 */
class EstimatePolicy
{
    /**
     * Determine whether the user can view any estimates.
     */
    public function viewAny(User $user): bool
    {
        if (!$user->current_organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.view', $context) 
            || $user->hasPermission('budget-estimates.view_all', $context);
    }

    /**
     * Determine whether the user can view the estimate.
     */
    public function view(User $user, Estimate $estimate): bool
    {
        // Проверка организации
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля в первую очередь
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        // Если есть право на просмотр всех - разрешить
        if ($user->hasPermission('budget-estimates.view_all', $context)) {
            return true;
        }

        // Если есть право на просмотр своих
        return $user->hasPermission('budget-estimates.view', $context);
    }

    /**
     * Determine whether the user can create estimates.
     */
    public function create(User $user): bool
    {
        if (!$user->current_organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.create', $context);
    }

    /**
     * Determine whether the user can update the estimate.
     */
    public function update(User $user, Estimate $estimate): bool
    {
        // Проверка организации - если current_organization_id не установлен, запрещаем доступ
        if (!$user->current_organization_id) {
            return false;
        }

        // Проверка организации (приводим к int для корректного сравнения)
        if ((int)$user->current_organization_id !== (int)$estimate->organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля в первую очередь
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        // Если смета утверждена - нужно специальное право
        if ($estimate->isApproved()) {
            return $user->hasPermission('budget-estimates.edit_approved', $context);
        }

        // Для черновиков достаточно обычного права на редактирование
        return $user->hasPermission('budget-estimates.edit', $context);
    }

    /**
     * Determine whether the user can delete the estimate.
     */
    public function delete(User $user, Estimate $estimate): bool
    {
        // Проверка организации
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }

        // Нельзя удалить утвержденную смету
        if ($estimate->isApproved()) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.delete', $context);
    }

    /**
     * Determine whether the user can approve the estimate.
     */
    public function approve(User $user, Estimate $estimate): bool
    {
        // Проверка организации
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }

        // Можно утвердить только сметы в статусе "на проверке"
        if ($estimate->status !== 'in_review') {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.approve', $context);
    }

    /**
     * Determine whether the user can import estimates.
     */
    public function import(User $user): bool
    {
        if (!$user->current_organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.import', $context);
    }

    /**
     * Determine whether the user can export the estimate.
     */
    public function export(User $user, Estimate $estimate): bool
    {
        // Проверка организации
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.export', $context);
    }

    /**
     * Determine whether the user can manage templates.
     */
    public function manageTemplates(User $user): bool
    {
        if (!$user->current_organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.templates.manage', $context);
    }

    /**
     * Determine whether the user can view analytics.
     */
    public function viewAnalytics(User $user): bool
    {
        if (!$user->current_organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.analytics', $context);
    }

    /**
     * Determine whether the user can create versions.
     */
    public function createVersion(User $user, Estimate $estimate): bool
    {
        // Проверка организации
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }

        // Версии можно создавать только для утвержденных смет
        if (!$estimate->isApproved()) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.versions.create', $context);
    }

    /**
     * Determine whether the user can compare versions.
     */
    public function compareVersions(User $user): bool
    {
        if (!$user->current_organization_id) {
            return false;
        }

        $context = [
            'context_type' => 'organization',
            'organization_id' => $user->current_organization_id
        ];

        // Проверяем wildcard для всего модуля
        if ($user->hasPermission('budget-estimates.*', $context)) {
            return true;
        }

        return $user->hasPermission('budget-estimates.versions.compare', $context);
    }
}


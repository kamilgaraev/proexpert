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
        return $user->hasPermission('budget-estimates.view') 
            || $user->hasPermission('budget-estimates.view_all');
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

        // Если есть право на просмотр всех - разрешить
        if ($user->hasPermission('budget-estimates.view_all')) {
            return true;
        }

        // Если есть право на просмотр своих
        return $user->hasPermission('budget-estimates.view');
    }

    /**
     * Determine whether the user can create estimates.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('budget-estimates.create');
    }

    /**
     * Determine whether the user can update the estimate.
     */
    public function update(User $user, Estimate $estimate): bool
    {
        // Проверка организации
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }

        // Если смета утверждена - нужно специальное право
        if ($estimate->isApproved()) {
            return $user->hasPermission('budget-estimates.edit_approved');
        }

        // Для черновиков достаточно обычного права на редактирование
        return $user->hasPermission('budget-estimates.edit');
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

        return $user->hasPermission('budget-estimates.delete');
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

        return $user->hasPermission('budget-estimates.approve');
    }

    /**
     * Determine whether the user can import estimates.
     */
    public function import(User $user): bool
    {
        return $user->hasPermission('budget-estimates.import');
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

        return $user->hasPermission('budget-estimates.export');
    }

    /**
     * Determine whether the user can manage templates.
     */
    public function manageTemplates(User $user): bool
    {
        return $user->hasPermission('budget-estimates.templates.manage');
    }

    /**
     * Determine whether the user can view analytics.
     */
    public function viewAnalytics(User $user): bool
    {
        return $user->hasPermission('budget-estimates.analytics');
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

        return $user->hasPermission('budget-estimates.versions.create');
    }

    /**
     * Determine whether the user can compare versions.
     */
    public function compareVersions(User $user): bool
    {
        return $user->hasPermission('budget-estimates.versions.compare');
    }
}


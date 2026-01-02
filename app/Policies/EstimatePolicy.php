<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Estimate;

class EstimatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasModulePermission($user, ['view', 'view_all', '*']);
    }

    public function view(User $user, Estimate $estimate): bool
    {
        // Проверка организации
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }
        
        // Если есть любой из permissions для просмотра
        return $this->hasModulePermission($user, ['view', 'view_all', 'manage', '*']);
    }
    
    /**
     * Проверка наличия прав модуля (с поддержкой wildcard)
     */
    private function hasModulePermission(User $user, array $permissions): bool
    {
        // Сначала проверяем wildcard для всего модуля
        if ($user->hasPermission("budget-estimates.*")) {
            return true;
        }
        
        // Затем проверяем конкретные права
        foreach ($permissions as $permission) {
            if ($permission === '*') {
                // Если в списке есть wildcard, проверяем его отдельно
                if ($user->hasPermission("budget-estimates.*")) {
                    return true;
                }
            } else {
                if ($user->hasPermission("budget-estimates.{$permission}")) {
                    return true;
                }
            }
        }
        
        return false;
    }

    public function create(User $user): bool
    {
        return $this->hasModulePermission($user, ['create', '*']);
    }

    public function update(User $user, Estimate $estimate): bool
    {
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }
        
        // Проверяем wildcard для всего модуля в первую очередь
        if ($user->hasPermission("budget-estimates.*")) {
            return true;
        }
        
        if ($estimate->isApproved()) {
            return $this->hasModulePermission($user, ['edit_approved', '*']);
        }
        
        return $this->hasModulePermission($user, ['edit', 'manage', '*']);
    }

    public function delete(User $user, Estimate $estimate): bool
    {
        return $this->hasModulePermission($user, ['delete', '*'])
            && $user->current_organization_id === $estimate->organization_id
            && !$estimate->isApproved();
    }

    public function approve(User $user, Estimate $estimate): bool
    {
        return $this->hasModulePermission($user, ['approve', '*'])
            && $user->current_organization_id === $estimate->organization_id
            && $estimate->status === 'in_review';
    }

    public function import(User $user): bool
    {
        return $this->hasModulePermission($user, ['import', '*']);
    }

    public function export(User $user, Estimate $estimate): bool
    {
        return $this->hasModulePermission($user, ['export', '*'])
            && $user->current_organization_id === $estimate->organization_id;
    }
}


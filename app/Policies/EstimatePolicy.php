<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Estimate;

class EstimatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('budget-estimates.view') || $user->hasPermission('budget-estimates.view_all');
    }

    public function view(User $user, Estimate $estimate): bool
    {
        // Проверка организации
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }
        
        // Если есть любой из permissions для просмотра
        return $user->hasPermission('budget-estimates.view') 
            || $user->hasPermission('budget-estimates.view_all')
            || $user->hasPermission('budget-estimates.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('budget-estimates.create');
    }

    public function update(User $user, Estimate $estimate): bool
    {
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }
        
        if ($estimate->isApproved()) {
            return $user->hasPermission('budget-estimates.edit_approved');
        }
        
        return $user->hasPermission('budget-estimates.edit');
    }

    public function delete(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission('budget-estimates.delete') 
            && $user->current_organization_id === $estimate->organization_id
            && !$estimate->isApproved();
    }

    public function approve(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission('budget-estimates.approve') 
            && $user->current_organization_id === $estimate->organization_id
            && $estimate->status === 'in_review';
    }

    public function import(User $user): bool
    {
        return $user->hasPermission('budget-estimates.import');
    }

    public function export(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission('budget-estimates.export') 
            && $user->current_organization_id === $estimate->organization_id;
    }
}


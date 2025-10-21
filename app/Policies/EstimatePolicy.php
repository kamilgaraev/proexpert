<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Estimate;

class EstimatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('estimates.view') || $user->hasPermission('estimates.view_all');
    }

    public function view(User $user, Estimate $estimate): bool
    {
        if ($user->hasPermission('estimates.view_all')) {
            return $user->current_organization_id === $estimate->organization_id;
        }
        
        return $user->hasPermission('estimates.view') 
            && $user->current_organization_id === $estimate->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('estimates.create');
    }

    public function update(User $user, Estimate $estimate): bool
    {
        if ($user->current_organization_id !== $estimate->organization_id) {
            return false;
        }
        
        if ($estimate->isApproved()) {
            return $user->hasPermission('estimates.edit_approved');
        }
        
        return $user->hasPermission('estimates.edit');
    }

    public function delete(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission('estimates.delete') 
            && $user->current_organization_id === $estimate->organization_id
            && !$estimate->isApproved();
    }

    public function approve(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission('estimates.approve') 
            && $user->current_organization_id === $estimate->organization_id
            && $estimate->status === 'in_review';
    }

    public function import(User $user): bool
    {
        return $user->hasPermission('estimates.import');
    }

    public function export(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission('estimates.export') 
            && $user->current_organization_id === $estimate->organization_id;
    }
}


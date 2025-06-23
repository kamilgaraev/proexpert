<?php

namespace App\Policies;

use App\Models\ActReport;
use App\Models\User;

class ActReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-act-reports');
    }

    public function view(User $user, ActReport $actReport): bool
    {
        return $user->hasPermissionTo('view-act-reports') 
               && $user->organization_id === $actReport->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-act-reports');
    }

    public function update(User $user, ActReport $actReport): bool
    {
        return $user->hasPermissionTo('update-act-reports') 
               && $user->organization_id === $actReport->organization_id;
    }

    public function delete(User $user, ActReport $actReport): bool
    {
        return $user->hasPermissionTo('delete-act-reports') 
               && $user->organization_id === $actReport->organization_id;
    }
} 
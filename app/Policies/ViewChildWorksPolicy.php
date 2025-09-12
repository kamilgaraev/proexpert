<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ViewChildWorksPolicy
{
    use HandlesAuthorization;

    /**
     * Определить, может ли пользователь просматривать детализированные работы дочерних организаций.
     */
    public function view(User $user, Project $project): bool
    {
        // Используем новую систему авторизации
        $hasPermission = $user->hasPermission('projects.view_child_works', [
            'organization_id' => $user->current_organization_id
        ]);

        $currentOrgId = $user->current_organization_id;

        // 1) Пользователь из головной организации с разрешением
        if ($hasPermission && $user->organizations()->where('organizations.id', $project->organization_id)->exists()) {
            return true;
        }

        // 2) Пользователь из дочерней организации, которая прикреплена к проекту
        $attached = $project->organizations()->where('organizations.id', $currentOrgId)->exists();

        return $attached;
    }
} 
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
        // У пользователя должно быть право projects.view_child_works в контексте головной организации проекта
        $hasPermission = method_exists($user, 'hasPermission')
            ? $user->hasPermission('projects.view_child_works')
            : false;

        // Пользователь принадлежит головной организации проекта?
        $belongsToOrg = $user->organizations()->where('organizations.id', $project->organization_id)->exists();

        return $hasPermission && $belongsToOrg;
    }
} 
<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\UserProjectAccessMode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UserProjectAccessService
{
    public function modeFor(User $user, int $organizationId): UserProjectAccessMode
    {
        $mode = DB::table('organization_user')
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->value('project_access_mode');

        return UserProjectAccessMode::tryFrom((string) $mode)
            ?? UserProjectAccessMode::ASSIGNED_PROJECTS;
    }

    public function queryAccessibleProjects(User $user, int $organizationId): Builder
    {
        $query = Project::query()
            ->accessibleByOrganization($organizationId)
            ->where('is_archived', false);

        if ($this->modeFor($user, $organizationId) === UserProjectAccessMode::ALL_PROJECTS) {
            return $query;
        }

        return $query->whereHas('users', function (Builder $userQuery) use ($user): void {
            $userQuery
                ->where('users.id', $user->id)
                ->where('project_user.is_active', true);
        });
    }

    public function canAccessProject(User $user, Project $project, int $organizationId): bool
    {
        return $this->queryAccessibleProjects($user, $organizationId)
            ->where('projects.id', $project->id)
            ->exists();
    }

    public function syncAssignments(User $member, int $organizationId, array $projectIds, User $actor): void
    {
        $organizationProjectIds = Project::query()
            ->accessibleByOrganization($organizationId)
            ->where('is_archived', false)
            ->pluck('projects.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $allowedProjectIds = array_values(array_intersect(
            array_map('intval', $projectIds),
            $organizationProjectIds
        ));

        $member->assignedProjects()
            ->whereIn('projects.id', $organizationProjectIds)
            ->detach();

        $payload = [];
        foreach ($allowedProjectIds as $projectId) {
            $payload[$projectId] = [
                'role' => 'member',
                'is_active' => true,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
            ];
        }

        if ($payload !== []) {
            $member->assignedProjects()->syncWithoutDetaching($payload);
        }
    }
}

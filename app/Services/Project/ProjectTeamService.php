<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Exceptions\BusinessLogicException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

use function trans_message;

class ProjectTeamService
{
    private const DEFAULT_PROJECT_ROLE = 'member';
    private const MAX_MEMBERS_PER_PAGE = 1000;

    public function paginateAvailableMembers(
        Project $project,
        int $organizationId,
        array $filters = [],
        int $perPage = 50
    ): LengthAwarePaginator {
        $this->assertProjectAccessible($project, $organizationId);

        $context = AuthorizationContext::getOrganizationContext($organizationId);
        $perPage = max(1, min($perPage, self::MAX_MEMBERS_PER_PAGE));
        $search = trim((string) ($filters['search'] ?? ''));
        $name = trim((string) ($filters['name'] ?? ''));
        $email = trim((string) ($filters['email'] ?? ''));

        $query = User::query()
            ->where('users.is_active', true)
            ->whereHas('organizations', static function (Builder $organizationQuery) use ($organizationId): void {
                $organizationQuery
                    ->where('organization_user.organization_id', $organizationId)
                    ->where('organization_user.is_active', true);
            })
            ->with([
                'organizations' => static function ($organizationQuery) use ($organizationId): void {
                    $organizationQuery->where('organizations.id', $organizationId);
                },
                'assignedProjects' => static function ($projectQuery): void {
                    $projectQuery->wherePivot('is_active', true);
                },
                'roleAssignments' => static function ($roleQuery) use ($context): void {
                    $roleQuery
                        ->where('context_id', $context->id)
                        ->where('is_active', true);
                },
            ]);

        if ($search !== '') {
            $query->where(static function (Builder $memberQuery) use ($search): void {
                $memberQuery
                    ->where('users.name', 'like', '%' . $search . '%')
                    ->orWhere('users.email', 'like', '%' . $search . '%');
            });
        }

        if ($name !== '') {
            $query->where('users.name', 'like', '%' . $name . '%');
        }

        if ($email !== '') {
            $query->where('users.email', 'like', '%' . $email . '%');
        }

        return $query
            ->orderBy('users.name')
            ->orderBy('users.email')
            ->paginate($perPage);
    }

    public function assignMember(Project $project, User $member, User $actor, int $organizationId): void
    {
        $this->assertProjectAccessible($project, $organizationId);
        $this->assertMemberBelongsToOrganization($member, $organizationId);

        DB::transaction(function () use ($project, $member, $actor): void {
            $existingAssignment = DB::table('project_user')
                ->where('project_id', $project->id)
                ->where('user_id', $member->id)
                ->first(['role']);

            $payload = [
                'role' => ($existingAssignment?->role ?: self::DEFAULT_PROJECT_ROLE),
                'is_active' => true,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'updated_at' => now(),
            ];

            if ($existingAssignment !== null) {
                DB::table('project_user')
                    ->where('project_id', $project->id)
                    ->where('user_id', $member->id)
                    ->update($payload);

                return;
            }

            $project->users()->attach($member->id, [
                ...$payload,
                'created_at' => now(),
            ]);
        });
    }

    public function detachMember(Project $project, User $member, int $organizationId): bool
    {
        $this->assertProjectAccessible($project, $organizationId);

        return $project->users()->detach($member->id) > 0;
    }

    private function assertProjectAccessible(Project $project, int $organizationId): void
    {
        if ($organizationId <= 0) {
            throw new BusinessLogicException(trans_message('project.organization_context_missing'), 400);
        }

        $isAccessible = Project::query()
            ->accessibleByOrganization($organizationId)
            ->whereKey($project->id)
            ->exists();

        if (!$isAccessible) {
            throw new BusinessLogicException(trans_message('project.not_found_in_organization'), 404);
        }
    }

    private function assertMemberBelongsToOrganization(User $member, int $organizationId): void
    {
        $isMember = $member->is_active
            && $member->organizations()
                ->where('organization_user.organization_id', $organizationId)
                ->where('organization_user.is_active', true)
                ->exists();

        if (!$isMember) {
            throw new BusinessLogicException(trans_message('project.team_member_not_found'), 404);
        }
    }
}

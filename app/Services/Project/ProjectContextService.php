<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\Domain\Project\ValueObjects\ProjectRoleConfig;
use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectOrganization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProjectContextService
{
    private const CACHE_TTL = 3600;

    public function getContext(Project $project, Organization $organization): ProjectContext
    {
        $cacheKey = $this->getCacheKey($project->id, $organization->id);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($project, $organization) {
            return $this->buildContext($project, $organization);
        });
    }

    public function getContextForUser(Project $project, User $user): ?ProjectContext
    {
        $organization = $user->organization;

        if (!$organization instanceof Organization) {
            return null;
        }

        return $this->getContext($project, $organization);
    }

    public function invalidateContext(int $projectId, int $organizationId): void
    {
        Cache::forget($this->getCacheKey($projectId, $organizationId));

        Log::debug('Project context cache invalidated', [
            'project_id' => $projectId,
            'organization_id' => $organizationId,
        ]);
    }

    public function getOrganizationRole(Project $project, Organization $organization): ?ProjectOrganizationRole
    {
        if ($project->organization_id === $organization->id) {
            return ProjectOrganizationRole::OWNER;
        }

        $pivot = ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->first();

        if (!$pivot instanceof ProjectOrganization) {
            return null;
        }

        $roleValue = $pivot->getRawOriginal('role_new') ?: $pivot->getRawOriginal('role');

        return ProjectOrganizationRole::tryFrom($roleValue);
    }

    public function getRoleConfig(ProjectOrganizationRole $role): ProjectRoleConfig
    {
        return new ProjectRoleConfig(
            role: $role,
            permissions: $this->getPermissionsForRole($role),
            canManageContracts: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CONTRACTOR,
            ], true),
            canViewFinances: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CUSTOMER,
            ], true),
            canManageWorks: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CONTRACTOR,
                ProjectOrganizationRole::SUBCONTRACTOR,
            ], true),
            canManageWarehouse: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CONTRACTOR,
            ], true),
            canInviteParticipants: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
            ], true),
            displayLabel: $role->label()
        );
    }

    public function getAllProjectParticipants(Project $project): array
    {
        $participants = [];

        $allParticipants = ProjectOrganization::query()
            ->useWritePdo()
            ->with('organization')
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($allParticipants as $participantRecord) {
            $organization = $participantRecord->organization;
            if (!$organization instanceof Organization) {
                continue;
            }

            $roleValue = $participantRecord->getRawOriginal('role_new') ?: $participantRecord->getRawOriginal('role');
            $role = ProjectOrganizationRole::tryFrom($roleValue);

            if (!$role instanceof ProjectOrganizationRole) {
                continue;
            }

            $participants[$organization->id] = [
                'organization' => $organization,
                'role' => $role,
                'is_active' => (bool) $participantRecord->is_active,
                'is_owner' => $project->organization_id === $organization->id,
                'added_at' => $participantRecord->created_at,
                'invited_at' => $participantRecord->invited_at,
                'accepted_at' => $participantRecord->accepted_at,
            ];
        }

        return array_values($participants);
    }

    public function canOrganizationAccessProject(Project $project, Organization $organization): bool
    {
        if ($project->organization_id === $organization->id) {
            return true;
        }

        return ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->exists();
    }

    public function canUserAccessProject(User $user, Project $project): bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        if ($user->current_organization_id) {
            $organization = Organization::find($user->current_organization_id);
            if ($organization instanceof Organization && $this->canOrganizationAccessProject($project, $organization)) {
                return true;
            }
        }

        if ($user->hasPermission('projects.view', ['project_id' => $project->id])) {
            return true;
        }

        return $user->isOrganizationOwner($project->organization_id);
    }

    public function canUserManageProject(User $user, Project $project): bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        if ($user->hasPermission('projects.edit', ['project_id' => $project->id])) {
            return true;
        }

        return $user->isOrganizationOwner($project->organization_id);
    }

    public function getAccessibleProjects(Organization $organization): array
    {
        $allProjects = Project::query()
            ->useWritePdo()
            ->accessibleByOrganization($organization->id)
            ->where('is_archived', false)
            ->get();

        return $allProjects->map(function (Project $project) use ($organization) {
            $role = $this->getOrganizationRole($project, $organization);

            if (!$role instanceof ProjectOrganizationRole) {
                Log::warning('Project without role found', [
                    'project_id' => $project->id,
                    'organization_id' => $organization->id,
                ]);

                return null;
            }

            return [
                'project' => $project,
                'role' => $role,
                'is_owner' => $project->organization_id === $organization->id,
            ];
        })->filter()->values()->toArray();
    }

    private function buildContext(Project $project, Organization $organization): ProjectContext
    {
        $role = $this->getOrganizationRole($project, $organization);

        if (!$role instanceof ProjectOrganizationRole) {
            throw new \RuntimeException('Organization does not have access to this project');
        }

        $roleConfig = $this->getRoleConfig($role);

        return new ProjectContext(
            projectId: $project->id,
            projectName: $project->name,
            organizationId: $organization->id,
            organizationName: $organization->name,
            role: $role,
            roleConfig: $roleConfig,
            isOwner: $project->organization_id === $organization->id
        );
    }

    private function getPermissionsForRole(ProjectOrganizationRole $role): array
    {
        return match ($role) {
            ProjectOrganizationRole::OWNER => [
                'manage_project',
                'view_all',
                'manage_contracts',
                'manage_works',
                'manage_warehouse',
                'manage_participants',
                'view_finances',
                'manage_reports',
            ],
            ProjectOrganizationRole::GENERAL_CONTRACTOR => [
                'view_project',
                'manage_contracts',
                'manage_works',
                'manage_warehouse',
                'manage_participants',
                'view_finances',
                'create_reports',
            ],
            ProjectOrganizationRole::CONTRACTOR => [
                'view_project',
                'manage_own_contracts',
                'manage_works',
                'manage_warehouse',
                'view_own_finances',
                'create_reports',
            ],
            ProjectOrganizationRole::SUBCONTRACTOR => [
                'view_project',
                'view_assigned_works',
                'submit_works',
                'view_own_materials',
            ],
            ProjectOrganizationRole::CUSTOMER => [
                'view_project',
                'view_all_contracts',
                'view_all_works',
                'view_finances',
                'view_reports',
                'approve_works',
            ],
            ProjectOrganizationRole::CONSTRUCTION_SUPERVISION => [
                'view_project',
                'view_all_works',
                'inspect_works',
                'create_inspection_reports',
                'view_documents',
            ],
            ProjectOrganizationRole::DESIGNER => [
                'view_project',
                'manage_design_documents',
                'view_works',
                'create_design_reports',
            ],
            ProjectOrganizationRole::OBSERVER => [
                'view_project',
                'view_basic_info',
            ],
            ProjectOrganizationRole::PARENT_ADMINISTRATOR => [
                'view_project',
                'view_basic_info',
            ],
        };
    }

    private function getCacheKey(int $projectId, int $organizationId): string
    {
        return "project:{$projectId}:org:{$organizationId}:context";
    }
}

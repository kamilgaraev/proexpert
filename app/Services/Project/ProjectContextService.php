<?php

namespace App\Services\Project;

use App\Models\Project;
use App\Models\Organization;
use App\Models\User;
use App\Enums\ProjectOrganizationRole;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Domain\Project\ValueObjects\ProjectRoleConfig;
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
        
        if (!$organization) {
            return null;
        }

        return $this->getContext($project, $organization);
    }

    public function invalidateContext(int $projectId, int $organizationId): void
    {
        $cacheKey = $this->getCacheKey($projectId, $organizationId);
        Cache::forget($cacheKey);

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

        $pivot = $project->organizations()
            ->wherePivot('organization_id', $organization->id)
            ->wherePivot('is_active', true)
            ->first()?->pivot;

        if (!$pivot) {
            return null;
        }

        $roleValue = $pivot->role_new ?? $pivot->role;
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
            ]),
            canViewFinances: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CUSTOMER,
            ]),
            canManageWorks: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CONTRACTOR,
                ProjectOrganizationRole::SUBCONTRACTOR,
            ]),
            canManageWarehouse: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CONTRACTOR,
            ]),
            canInviteParticipants: in_array($role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
            ]),
            displayLabel: $role->label()
        );
    }

    public function getAllProjectParticipants(Project $project): array
    {
        $participants = [];

        $owner = $project->organization;
        if ($owner) {
            $participants[] = [
                'organization' => $owner,
                'role' => ProjectOrganizationRole::OWNER,
                'is_active' => true,
                'is_owner' => true,
            ];
        }

        $otherParticipants = $project->organizations()
            ->wherePivot('is_active', true)
            ->get();

        foreach ($otherParticipants as $org) {
            $pivot = $org->pivot;
            $roleValue = $pivot->role_new ?? $pivot->role;
            $role = ProjectOrganizationRole::tryFrom($roleValue);

            if ($role) {
                $participants[] = [
                    'organization' => $org,
                    'role' => $role,
                    'is_active' => $pivot->is_active ?? true,
                    'is_owner' => false,
                    'added_at' => $pivot->created_at,
                    'invited_at' => $pivot->invited_at,
                    'accepted_at' => $pivot->accepted_at,
                ];
            }
        }

        return $participants;
    }

    public function canOrganizationAccessProject(Project $project, Organization $organization): bool
    {
        if ($project->organization_id === $organization->id) {
            return true;
        }

        return $project->organizations()
            ->wherePivot('organization_id', $organization->id)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function getAccessibleProjects(Organization $organization): array
    {
        $ownedProjects = Project::where('organization_id', $organization->id)
            ->where('is_archived', false)
            ->get();

        $participantProjects = $organization->projects()
            ->wherePivot('is_active', true)
            ->where('is_archived', false)
            ->get();

        $allProjects = $ownedProjects->merge($participantProjects)->unique('id');

        return $allProjects->map(function ($project) use ($organization) {
            $role = $this->getOrganizationRole($project, $organization);
            
            // Пропускаем проекты без роли (не должно быть, но для безопасности)
            if (!$role) {
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

        if (!$role) {
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
        };
    }

    private function getCacheKey(int $projectId, int $organizationId): string
    {
        return "project:{$projectId}:org:{$organizationId}:context";
    }
}


<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\ProjectOrganizationRole;
use App\Events\ProjectOrganizationAdded;
use App\Events\ProjectOrganizationRoleChanged;
use App\Exceptions\BusinessLogicException;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Services\Organization\OrganizationProfileService;

class ProjectParticipantService
{
    public function __construct(
        private readonly LoggingService $logging,
        private readonly OrganizationProfileService $organizationProfileService,
        private readonly ProjectContextService $projectContextService
    ) {
    }

    public function attach(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $role,
        ?User $user = null
    ): void {
        if ($project->organizations()->where('organizations.id', $organizationId)->exists()) {
            throw new BusinessLogicException('Организация уже добавлена в проект.', 409);
        }

        $organization = $this->findOrganization($organizationId);

        $this->enforceUniqueCustomer($project, $role, $organizationId);
        $this->validateRoleCapability($organization, $role);

        $project->organizations()->attach($organizationId, [
            'role' => $role->value,
            'role_new' => $role->value,
            'is_active' => true,
            'added_by_user_id' => $user?->id,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->projectContextService->invalidateContext($project->id, $organizationId);

        $this->logging->business('Organization added to project', [
            'project_id' => $project->id,
            'organization_id' => $organizationId,
            'role' => $role->value,
            'added_by' => $user?->id,
        ]);

        if (\in_array($role->value, [
            ProjectOrganizationRole::CONTRACTOR->value,
            ProjectOrganizationRole::SUBCONTRACTOR->value,
        ], true)) {
            $this->ensureContractorExists($project->organization_id, $organizationId);
        }

        event(new ProjectOrganizationAdded($project, $organization, $role, $user));
    }

    public function updateRole(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $newRole,
        ?User $user = null
    ): void {
        if ($organizationId === $project->organization_id) {
            throw new BusinessLogicException('Нельзя изменить роль владельца проекта.', 400);
        }

        $participant = $project->organizations()
            ->wherePivot('organization_id', $organizationId)
            ->first();

        if (!$participant instanceof Organization) {
            throw new BusinessLogicException('Организация не является участником проекта.', 404);
        }

        $oldRoleValue = $participant->pivot->role_new ?? $participant->pivot->role;
        $oldRole = ProjectOrganizationRole::from($oldRoleValue);

        $this->enforceUniqueCustomer($project, $newRole, $organizationId);
        $this->validateRoleCapability($participant, $newRole);

        $project->organizations()->updateExistingPivot($organizationId, [
            'role' => $newRole->value,
            'role_new' => $newRole->value,
            'updated_at' => now(),
        ]);

        $this->projectContextService->invalidateContext($project->id, $organizationId);

        $this->logging->business('Organization role updated in project', [
            'project_id' => $project->id,
            'organization_id' => $organizationId,
            'old_role' => $oldRole->value,
            'new_role' => $newRole->value,
        ]);

        event(new ProjectOrganizationRoleChanged($project, $participant, $oldRole, $newRole, $user));
    }

    public function setActiveState(Project $project, int $organizationId, bool $isActive): void
    {
        if ($organizationId === $project->organization_id) {
            throw new BusinessLogicException('Нельзя изменить активность владельца проекта.', 400);
        }

        $participant = $project->organizations()
            ->wherePivot('organization_id', $organizationId)
            ->first();

        if (!$participant instanceof Organization) {
            throw new BusinessLogicException('Организация не является участником проекта.', 404);
        }

        $roleValue = $participant->pivot->role_new ?? $participant->pivot->role;
        $role = ProjectOrganizationRole::from($roleValue);

        if ($isActive) {
            $this->enforceUniqueCustomer($project, $role, $organizationId);
        }

        $project->organizations()->updateExistingPivot($organizationId, [
            'is_active' => $isActive,
            'updated_at' => now(),
        ]);

        $this->projectContextService->invalidateContext($project->id, $organizationId);
    }

    public function enforceUniqueCustomer(
        Project $project,
        ProjectOrganizationRole $role,
        ?int $organizationId = null
    ): void {
        if ($role !== ProjectOrganizationRole::CUSTOMER) {
            return;
        }

        $existingCustomer = $project->organizations()
            ->wherePivot('is_active', true)
            ->where(function ($query): void {
                $query
                    ->where('project_organization.role_new', ProjectOrganizationRole::CUSTOMER->value)
                    ->orWhere(function ($fallbackQuery): void {
                        $fallbackQuery
                            ->whereNull('project_organization.role_new')
                            ->where('project_organization.role', ProjectOrganizationRole::CUSTOMER->value);
                    });
            })
            ->first();

        if ($existingCustomer instanceof Organization && (int) $existingCustomer->id !== (int) $organizationId) {
            throw new BusinessLogicException('В проекте уже есть активный заказчик.', 409);
        }
    }

    public function resolveAllowedRoles(Organization $organization): array
    {
        return $this->organizationProfileService->getProfile($organization)->getAllowedProjectRoles();
    }

    public function resolveCapabilities(Organization $organization): array
    {
        return $this->organizationProfileService->getProfile($organization)->getCapabilities();
    }

    public function assertCanAssumeRole(Organization $organization, ProjectOrganizationRole $role): void
    {
        $this->validateRoleCapability($organization, $role);
    }

    private function findOrganization(int $organizationId): Organization
    {
        $organization = Organization::find($organizationId);

        if (!$organization instanceof Organization) {
            throw new BusinessLogicException('Организация не найдена.', 404);
        }

        return $organization;
    }

    private function validateRoleCapability(Organization $organization, ProjectOrganizationRole $role): void
    {
        $validation = $this->organizationProfileService->validateCapabilitiesForRole($organization, $role);

        if (!$validation->isValid) {
            throw new BusinessLogicException(
                'Организация не может выполнять данную роль: ' . implode(', ', $validation->errors),
                422
            );
        }
    }

    private function ensureContractorExists(int $forOrgId, int $sourceOrgId): void
    {
        $sourceOrg = Organization::find($sourceOrgId);
        if (!$sourceOrg instanceof Organization) {
            return;
        }

        $exists = Contractor::query()
            ->where('organization_id', $forOrgId)
            ->where('source_organization_id', $sourceOrgId)
            ->exists();

        if ($exists) {
            return;
        }

        Contractor::create([
            'organization_id' => $forOrgId,
            'source_organization_id' => $sourceOrgId,
            'name' => $sourceOrg->name,
            'inn' => $sourceOrg->tax_number,
            'legal_address' => $sourceOrg->address,
            'phone' => $sourceOrg->phone,
            'email' => $sourceOrg->email,
            'contractor_type' => Contractor::TYPE_INVITED_ORGANIZATION,
            'connected_at' => now(),
            'sync_settings' => [
                'sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn'],
                'sync_interval_hours' => 24,
            ],
        ]);

        $this->logging->business('Contractor created from project participant', [
            'for_organization_id' => $forOrgId,
            'source_organization_id' => $sourceOrgId,
            'contractor_name' => $sourceOrg->name,
        ]);
    }
}

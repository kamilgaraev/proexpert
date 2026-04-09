<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\ProjectOrganizationRole;
use App\Events\ProjectOrganizationAdded;
use App\Events\ProjectOrganizationRemoved;
use App\Events\ProjectOrganizationRoleChanged;
use App\Exceptions\BusinessLogicException;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectOrganization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Services\Organization\OrganizationProfileService;
use Illuminate\Support\Facades\DB;

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
        $organization = $this->findOrganization($organizationId);
        $existingParticipant = $this->findParticipantRecord($project->id, $organizationId, true, true);

        if ($existingParticipant instanceof ProjectOrganization && (bool) $existingParticipant->is_active) {
            throw new BusinessLogicException(trans_message('project.participant_already_exists'), 409);
        }

        $this->enforceUniqueCustomer($project, $role, $organizationId);
        $this->validateRoleCapability($organization, $role);

        $now = now();
        $payload = [
            'role' => $this->resolveLegacyRoleValue($role),
            'role_new' => $role->value,
            'is_active' => true,
            'added_by_user_id' => $user?->id ?? $existingParticipant?->added_by_user_id,
            'invited_at' => $existingParticipant?->invited_at ?? $now,
            'accepted_at' => $now,
            'updated_at' => $now,
        ];

        DB::transaction(function () use ($project, $organizationId, $existingParticipant, $payload, $now): void {
            if ($existingParticipant instanceof ProjectOrganization) {
                ProjectOrganization::query()
                    ->whereKey($existingParticipant->getKey())
                    ->update($payload);

                return;
            }

            DB::table('project_organization')->insert([
                'project_id' => $project->id,
                'organization_id' => $organizationId,
                'role' => $payload['role'],
                'role_new' => $payload['role_new'],
                'is_active' => $payload['is_active'],
                'added_by_user_id' => $payload['added_by_user_id'],
                'invited_at' => $payload['invited_at'],
                'accepted_at' => $payload['accepted_at'],
                'created_at' => $now,
                'updated_at' => $payload['updated_at'],
            ]);
        });

        $this->invalidateProjectContexts($project);

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
            throw new BusinessLogicException(trans_message('project.owner_role_change_forbidden'), 400);
        }

        $participantRecord = $this->findParticipantRecord($project->id, $organizationId, false, true);

        if (!$participantRecord instanceof ProjectOrganization) {
            throw new BusinessLogicException(trans_message('project.participant_not_found'), 404);
        }

        $participant = $this->findOrganization($organizationId);
        $oldRole = $this->resolveRoleFromRecord($participantRecord);

        if (!$oldRole instanceof ProjectOrganizationRole) {
            throw new BusinessLogicException(trans_message('project.participant_role_update_error'), 422);
        }

        $this->enforceUniqueCustomer($project, $newRole, $organizationId);
        $this->validateRoleCapability($participant, $newRole);

        $updated = ProjectOrganization::query()
            ->whereKey($participantRecord->getKey())
            ->update([
                'role' => $this->resolveLegacyRoleValue($newRole),
                'role_new' => $newRole->value,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BusinessLogicException(trans_message('project.participant_role_update_error'), 409);
        }

        $this->invalidateProjectContexts($project);

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
            throw new BusinessLogicException(trans_message('project.owner_active_state_forbidden'), 400);
        }

        $participantRecord = $this->findParticipantRecord($project->id, $organizationId, true, true);

        if (!$participantRecord instanceof ProjectOrganization) {
            throw new BusinessLogicException(trans_message('project.participant_not_found'), 404);
        }

        $role = $this->resolveRoleFromRecord($participantRecord);

        if (!$role instanceof ProjectOrganizationRole) {
            throw new BusinessLogicException(trans_message('project.participant_role_update_error'), 422);
        }

        if ($isActive) {
            $this->enforceUniqueCustomer($project, $role, $organizationId);
        }

        if ((bool) $participantRecord->is_active === $isActive) {
            return;
        }

        $updated = ProjectOrganization::query()
            ->whereKey($participantRecord->getKey())
            ->update([
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BusinessLogicException(
                trans_message($isActive ? 'project.participant_activate_error' : 'project.participant_deactivate_error'),
                409
            );
        }

        $this->invalidateProjectContexts($project);
    }

    public function remove(Project $project, int $organizationId, ?User $user = null): void
    {
        if ($organizationId === $project->organization_id) {
            throw new BusinessLogicException(trans_message('project.owner_remove_forbidden'), 400);
        }

        $participantRecord = $this->findParticipantRecord($project->id, $organizationId, false, true);

        if (!$participantRecord instanceof ProjectOrganization || !(bool) $participantRecord->is_active) {
            return;
        }

        $role = $this->resolveRoleFromRecord($participantRecord);
        $organization = Organization::withTrashed()->find($organizationId);

        $updated = ProjectOrganization::query()
            ->whereKey($participantRecord->getKey())
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BusinessLogicException(trans_message('project.participant_remove_conflict'), 409);
        }

        $stillActive = ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->exists();

        if ($stillActive) {
            throw new BusinessLogicException(trans_message('project.participant_remove_conflict'), 409);
        }

        $this->invalidateProjectContexts($project);

        $this->logging->business('Organization removed from project', [
            'project_id' => $project->id,
            'organization_id' => $organizationId,
            'removed_by' => $user?->id,
        ]);

        if ($organization instanceof Organization && $role instanceof ProjectOrganizationRole) {
            event(new ProjectOrganizationRemoved($project, $organization, $role, $user));
        }
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
            throw new BusinessLogicException(trans_message('project.unique_customer_conflict'), 409);
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
            throw new BusinessLogicException(trans_message('project.organization_not_found'), 404);
        }

        return $organization;
    }

    private function findParticipantRecord(
        int $projectId,
        int $organizationId,
        bool $includeInactive = false,
        bool $preferLatest = false
    ): ?ProjectOrganization {
        $query = ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId);

        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        if ($preferLatest || $includeInactive) {
            $query->orderByDesc('is_active')->orderByDesc('id');
        }

        return $query->first();
    }

    private function resolveRoleFromRecord(ProjectOrganization $participantRecord): ?ProjectOrganizationRole
    {
        $roleValue = $participantRecord->getRawOriginal('role_new') ?: $participantRecord->getRawOriginal('role');

        if (!is_string($roleValue) || $roleValue === '') {
            return null;
        }

        return ProjectOrganizationRole::tryFrom($roleValue) ?? match ($roleValue) {
            'owner' => ProjectOrganizationRole::OWNER,
            'contractor' => ProjectOrganizationRole::CONTRACTOR,
            'child_contractor' => ProjectOrganizationRole::SUBCONTRACTOR,
            'observer' => ProjectOrganizationRole::OBSERVER,
            default => null,
        };
    }

    private function validateRoleCapability(Organization $organization, ProjectOrganizationRole $role): void
    {
        $validation = $this->organizationProfileService->validateCapabilitiesForRole($organization, $role);

        if (!$validation->isValid) {
            throw new BusinessLogicException(
                trans_message('project.role_capabilities_invalid', [
                    'errors' => implode(', ', $validation->errors),
                ]),
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

    private function invalidateProjectContexts(Project $project): void
    {
        $organizationIds = ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->pluck('organization_id')
            ->push($project->organization_id)
            ->unique();

        foreach ($organizationIds as $organizationId) {
            $this->projectContextService->invalidateContext($project->id, (int) $organizationId);
        }
    }

    private function resolveLegacyRoleValue(ProjectOrganizationRole $role): string
    {
        return match ($role) {
            ProjectOrganizationRole::OWNER => 'owner',
            ProjectOrganizationRole::CONTRACTOR,
            ProjectOrganizationRole::GENERAL_CONTRACTOR => 'contractor',
            ProjectOrganizationRole::SUBCONTRACTOR => 'child_contractor',
            ProjectOrganizationRole::CUSTOMER,
            ProjectOrganizationRole::CONSTRUCTION_SUPERVISION,
            ProjectOrganizationRole::DESIGNER,
            ProjectOrganizationRole::OBSERVER,
            ProjectOrganizationRole::PARENT_ADMINISTRATOR => 'observer',
        };
    }
}

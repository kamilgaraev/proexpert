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
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementRfiParticipantGuard;
use App\Services\Logging\LoggingService;
use App\Services\Organization\OrganizationProfileService;
use Illuminate\Support\Facades\DB;
use DomainException;

class ProjectParticipantService
{
    public function __construct(
        private readonly LoggingService $logging,
        private readonly OrganizationProfileService $organizationProfileService,
        private readonly ProjectContextService $projectContextService,
        private readonly ChangeManagementRfiParticipantGuard $rfiParticipantGuard
    ) {
    }

    /**
     * @return array{
     *     configured: bool,
     *     root_organization_id: ?int,
     *     parents: array<int, array{organization_id: int, parent_organization_id: ?int}>,
     *     participants: array<int, array{organization_id: int, organization_name: string, parent_organization_id: ?int}>
     * }
     */
    public function getHierarchy(Project $project): array
    {
        $activeIds = $this->activeParticipantOrganizationIds($project);
        $rows = DB::table('project_organization_hierarchy')
            ->where('project_id', $project->id)
            ->whereIn('organization_id', $activeIds)
            ->get(['organization_id', 'parent_organization_id'])
            ->keyBy('organization_id');
        $organizations = Organization::query()->whereIn('id', $activeIds)->pluck('name', 'id');
        $configured = $this->isHierarchyConfiguredFor($project, $activeIds, $rows);
        $savedRoots = $rows->filter(static fn ($row): bool => $row->parent_organization_id === null);
        $rootId = $configured
            ? (int) $savedRoots->first()->organization_id
            : ($savedRoots->count() === 1
                ? (int) $savedRoots->first()->organization_id
                : $this->customerRootOrganizationId($project));

        $participants = [];
        foreach ($activeIds as $organizationId) {
            $row = $rows->get($organizationId);
            $parentOrganizationId = $row?->parent_organization_id === null
                ? null
                : (int) $row->parent_organization_id;
            $participants[] = [
                'organization_id' => $organizationId,
                'organization_name' => (string) ($organizations[$organizationId] ?? ''),
                'parent_organization_id' => $parentOrganizationId !== null
                    && in_array($parentOrganizationId, $activeIds, true)
                        ? $parentOrganizationId
                        : null,
            ];
        }

        return [
            'configured' => $configured,
            'root_organization_id' => $rootId,
            'parents' => array_values(array_map(
                static fn (array $participant): array => [
                    'organization_id' => $participant['organization_id'],
                    'parent_organization_id' => $participant['parent_organization_id'],
                ],
                array_filter(
                    $participants,
                    static fn (array $participant): bool => $participant['organization_id'] !== $rootId
                )
            )),
            'participants' => $participants,
        ];
    }

    /** @param array<int, array{organization_id: int, parent_organization_id: int}> $parents */
    public function saveHierarchy(Project $project, int $rootOrganizationId, array $parents, ?User $user = null): array
    {
        DB::transaction(function () use ($project, $rootOrganizationId, $parents): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $activeIds = $this->activeParticipantOrganizationIds($project);
            if (!in_array($rootOrganizationId, $activeIds, true)) {
                throw new BusinessLogicException(trans_message('project.hierarchy_root_not_participant'), 422);
            }

            $customerRootId = $this->customerRootOrganizationId($project);
            if ($customerRootId !== null && $rootOrganizationId !== $customerRootId) {
                throw new BusinessLogicException(trans_message('project.hierarchy_customer_must_be_root'), 422);
            }

            $parentMap = [];
            foreach ($parents as $parent) {
                $organizationId = (int) ($parent['organization_id'] ?? 0);
                $parentOrganizationId = (int) ($parent['parent_organization_id'] ?? 0);
                if ($organizationId <= 0 || $parentOrganizationId <= 0 || isset($parentMap[$organizationId])) {
                    throw new BusinessLogicException(trans_message('project.hierarchy_invalid_tree'), 422);
                }
                $parentMap[$organizationId] = $parentOrganizationId;
            }

            $expectedIds = array_values(array_filter(
                $activeIds,
                static fn (int $id): bool => $id !== $rootOrganizationId
            ));
            $providedIds = array_keys($parentMap);
            sort($expectedIds);
            sort($providedIds);
            if ($providedIds !== $expectedIds) {
                throw new BusinessLogicException(trans_message('project.hierarchy_all_participants_required'), 422);
            }

            foreach ($parentMap as $organizationId => $parentOrganizationId) {
                if ($organizationId === $parentOrganizationId || !in_array($parentOrganizationId, $activeIds, true)) {
                    throw new BusinessLogicException(trans_message('project.hierarchy_invalid_tree'), 422);
                }
            }
            $this->assertAcyclicHierarchy($rootOrganizationId, $parentMap);

            DB::table('project_organization_hierarchy')
                ->where('project_id', $project->id)
                ->delete();

            $pending = $parentMap;
            $inserted = [$rootOrganizationId => true];
            $now = now();
            DB::table('project_organization_hierarchy')->insert([
                'project_id' => $project->id,
                'organization_id' => $rootOrganizationId,
                'parent_organization_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            while ($pending !== []) {
                $progress = false;
                foreach ($pending as $organizationId => $parentOrganizationId) {
                    if (!isset($inserted[$parentOrganizationId])) {
                        continue;
                    }
                    DB::table('project_organization_hierarchy')->insert([
                        'project_id' => $project->id,
                        'organization_id' => $organizationId,
                        'parent_organization_id' => $parentOrganizationId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $inserted[$organizationId] = true;
                    unset($pending[$organizationId]);
                    $progress = true;
                }
                if (!$progress) {
                    throw new BusinessLogicException(trans_message('project.hierarchy_invalid_tree'), 422);
                }
            }
        });

        $this->logging->business('Project participant hierarchy updated', [
            'project_id' => $project->id,
            'root_organization_id' => $rootOrganizationId,
            'updated_by' => $user?->id,
        ]);

        return $this->getHierarchy($project);
    }

    public function getParent(int $projectId, int $organizationId): ?int
    {
        if (!$this->isConfigured($projectId) || !$this->isActiveParticipant($projectId, $organizationId)) {
            return null;
        }

        $parentId = DB::table('project_organization_hierarchy')
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->value('parent_organization_id');

        return $parentId === null ? null : (int) $parentId;
    }

    /** @return array<int> */
    public function getChildren(int $projectId, int $organizationId): array
    {
        if (!$this->isConfigured($projectId) || !$this->isActiveParticipant($projectId, $organizationId)) {
            return [];
        }

        $project = Project::query()->find($projectId);
        if (!$project instanceof Project) {
            return [];
        }

        return DB::table('project_organization_hierarchy')
            ->where('project_id', $projectId)
            ->where('parent_organization_id', $organizationId)
            ->whereIn('organization_id', $this->activeParticipantOrganizationIds($project))
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function isConfigured(int $projectId): bool
    {
        $project = Project::query()->find($projectId);
        if (!$project instanceof Project) {
            return false;
        }

        $activeIds = $this->activeParticipantOrganizationIds($project);
        $rows = DB::table('project_organization_hierarchy')
            ->where('project_id', $projectId)
            ->get(['organization_id', 'parent_organization_id'])
            ->keyBy('organization_id');

        return $this->isHierarchyConfiguredFor($project, $activeIds, $rows);
    }

    private function activeParticipantOrganizationIds(Project $project): array
    {
        return ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->pluck('organization_id')
            ->map(static fn ($id): int => (int) $id)
            ->push((int) $project->organization_id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function isActiveParticipant(int $projectId, int $organizationId): bool
    {
        $project = Project::query()->find($projectId);
        return $project instanceof Project
            && in_array($organizationId, $this->activeParticipantOrganizationIds($project), true);
    }

    private function assertCanDeactivateParticipant(int $projectId, int $organizationId): void
    {
        try {
            $this->rfiParticipantGuard->assertCanDeactivate($projectId, $organizationId);
        } catch (DomainException $exception) {
            throw new BusinessLogicException($exception->getMessage(), 409, $exception);
        }
    }

    private function customerRootOrganizationId(Project $project): ?int
    {
        $customerId = ProjectOrganization::query()
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->whereRaw("COALESCE(NULLIF(role_new, ''), role) = ?", [ProjectOrganizationRole::CUSTOMER->value])
            ->value('organization_id');

        return $customerId === null ? null : (int) $customerId;
    }

    private function isHierarchyConfiguredFor(Project $project, array $activeIds, $rows): bool
    {
        if ($activeIds === [] || $rows->count() !== count($activeIds)) {
            return false;
        }

        foreach ($activeIds as $organizationId) {
            if (!$rows->has($organizationId)) {
                return false;
            }
        }

        $roots = $rows->filter(static fn ($row): bool => $row->parent_organization_id === null);
        if ($roots->count() !== 1) {
            return false;
        }

        $rootId = (int) $roots->first()->organization_id;
        $customerRootId = $this->customerRootOrganizationId($project);
        if ($customerRootId !== null && $rootId !== $customerRootId) {
            return false;
        }

        foreach ($rows as $organizationId => $row) {
            if ($row->parent_organization_id !== null
                && (!$rows->has((int) $row->parent_organization_id)
                    || (int) $row->parent_organization_id === (int) $organizationId)) {
                return false;
            }

            $visited = [];
            $cursor = (int) $organizationId;
            while ($cursor !== $rootId) {
                if (isset($visited[$cursor])) {
                    return false;
                }
                $visited[$cursor] = true;
                $parentId = $rows->get($cursor)?->parent_organization_id;
                if ($parentId === null) {
                    return false;
                }
                $cursor = (int) $parentId;
            }
        }

        return true;
    }

    /** @param array<int, int> $parentMap */
    private function assertAcyclicHierarchy(int $rootOrganizationId, array $parentMap): void
    {
        foreach ($parentMap as $organizationId => $parentOrganizationId) {
            $visited = [$organizationId => true];
            $cursor = $parentOrganizationId;
            while ($cursor !== $rootOrganizationId) {
                if (isset($visited[$cursor]) || !isset($parentMap[$cursor])) {
                    throw new BusinessLogicException(trans_message('project.hierarchy_invalid_tree'), 422);
                }
                $visited[$cursor] = true;
                $cursor = $parentMap[$cursor];
            }
        }
    }

    public function attach(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $role,
        ?User $user = null,
        bool $confirmedCapabilities = false
    ): void {
        DB::transaction(function () use ($project, $organizationId, $role, $user, $confirmedCapabilities): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->attachLocked($project, $organizationId, $role, $user, $confirmedCapabilities);
        });
    }

    private function attachLocked(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $role,
        ?User $user = null,
        bool $confirmedCapabilities = false
    ): void {
        $organization = $this->findOrganization($organizationId);
        $existingParticipant = $this->findParticipantRecord($project->id, $organizationId, true, true);

        if ($existingParticipant instanceof ProjectOrganization && (bool) $existingParticipant->is_active) {
            throw new BusinessLogicException(trans_message('project.participant_already_exists'), 409);
        }

        $this->enforceUniqueCustomer($project, $role, $organizationId);
        $this->validateRoleCapabilityUnlessConfirmed($organization, $role, $confirmedCapabilities);

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
        ?User $user = null,
        bool $confirmedCapabilities = false
    ): void {
        DB::transaction(function () use ($project, $organizationId, $newRole, $user, $confirmedCapabilities): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->updateRoleLocked($project, $organizationId, $newRole, $user, $confirmedCapabilities);
        });
    }

    private function updateRoleLocked(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $newRole,
        ?User $user = null,
        bool $confirmedCapabilities = false
    ): void {
        $participantRecord = $this->findParticipantRecord($project->id, $organizationId, false, true);

        if (!$participantRecord instanceof ProjectOrganization) {
            if ($organizationId === (int) $project->organization_id) {
                $participant = $this->findOrganization($organizationId);
                $this->enforceUniqueCustomer($project, $newRole, $organizationId);
                $this->validateRoleCapabilityUnlessConfirmed($participant, $newRole, $confirmedCapabilities);

                $now = now();
                DB::table('project_organization')->insert([
                    'project_id' => $project->id,
                    'organization_id' => $organizationId,
                    'role' => $this->resolveLegacyRoleValue($newRole),
                    'role_new' => $newRole->value,
                    'is_active' => true,
                    'added_by_user_id' => $user?->id,
                    'invited_at' => $project->created_at ?? $now,
                    'accepted_at' => $project->created_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->invalidateProjectContexts($project);

                $this->logging->business('Organization role updated in project', [
                    'project_id' => $project->id,
                    'organization_id' => $organizationId,
                    'old_role' => ProjectOrganizationRole::OWNER->value,
                    'new_role' => $newRole->value,
                ]);

                event(new ProjectOrganizationRoleChanged($project, $participant, ProjectOrganizationRole::OWNER, $newRole, $user));

                return;
            }

            if ($this->findParticipantRecord($project->id, $organizationId, true, true) instanceof ProjectOrganization) {
                throw new BusinessLogicException(trans_message('project.participant_inactive_role_change'), 422);
            }

            throw new BusinessLogicException(trans_message('project.participant_not_found'), 404);
        }

        $participant = $this->findOrganization($organizationId);
        $oldRole = $this->resolveRoleFromRecord($participantRecord);

        if (!$oldRole instanceof ProjectOrganizationRole) {
            throw new BusinessLogicException(trans_message('project.participant_role_update_error'), 422);
        }

        $this->enforceUniqueCustomer($project, $newRole, $organizationId);
        $this->validateRoleCapabilityUnlessConfirmed($participant, $newRole, $confirmedCapabilities);

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

        if (\in_array($newRole->value, [
            ProjectOrganizationRole::CONTRACTOR->value,
            ProjectOrganizationRole::SUBCONTRACTOR->value,
        ], true) && $organizationId !== $project->organization_id) {
            $this->ensureContractorExists($project->organization_id, $organizationId);
        }

        event(new ProjectOrganizationRoleChanged($project, $participant, $oldRole, $newRole, $user));
    }

    public function setActiveState(Project $project, int $organizationId, bool $isActive): void
    {
        DB::transaction(function () use ($project, $organizationId, $isActive): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->setActiveStateLocked($project, $organizationId, $isActive);
        });
    }

    private function setActiveStateLocked(Project $project, int $organizationId, bool $isActive): void
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

        if (!$isActive) {
            $this->assertCanDeactivateParticipant((int) $project->id, $organizationId);
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
        $removed = DB::transaction(function () use ($project, $organizationId): ?array {
            $lockedProject = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($organizationId === (int) $lockedProject->organization_id) {
                throw new BusinessLogicException(trans_message('project.owner_remove_forbidden'), 400);
            }

            $participantRecord = $this->findParticipantRecord((int) $lockedProject->id, $organizationId, false, true);
            if (!$participantRecord instanceof ProjectOrganization || !(bool) $participantRecord->is_active) {
                return null;
            }

            $this->assertCanDeactivateParticipant((int) $lockedProject->id, $organizationId);

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
                ->where('project_id', $lockedProject->id)
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->exists();
            if ($stillActive) {
                throw new BusinessLogicException(trans_message('project.participant_remove_conflict'), 409);
            }

            $this->invalidateProjectContexts($lockedProject);

            return [
                'project' => $lockedProject,
                'organization' => $organization instanceof Organization ? $organization : null,
                'role' => $role,
            ];
        });

        if ($removed === null) {
            return;
        }

        $this->logging->business('Organization removed from project', [
            'project_id' => $removed['project']->id,
            'organization_id' => $organizationId,
            'removed_by' => $user?->id,
        ]);

        if ($removed['organization'] instanceof Organization && $removed['role'] instanceof ProjectOrganizationRole) {
            event(new ProjectOrganizationRemoved(
                $removed['project'],
                $removed['organization'],
                $removed['role'],
                $user
            ));
        }
    }

    public function enforceUniqueCustomer(
        Project $project,
        ProjectOrganizationRole $role,
        ?int $organizationId = null
    ): void {
        if ($role === ProjectOrganizationRole::GENERAL_CONTRACTOR) {
            $conflict = ProjectOrganization::query()
                ->where('project_id', $project->id)
                ->where('is_active', true)
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', '!=', $organizationId))
                ->whereRaw("COALESCE(NULLIF(role_new, ''), role) = ?", [$role->value])
                ->exists();
            if ($conflict) {
                throw new BusinessLogicException(trans_message('project.unique_general_contractor_conflict'), 409);
            }

            return;
        }
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

    private function validateRoleCapabilityUnlessConfirmed(
        Organization $organization,
        ProjectOrganizationRole $role,
        bool $confirmedCapabilities
    ): void {
        if ($confirmedCapabilities) {
            return;
        }

        $this->validateRoleCapability($organization, $role);
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

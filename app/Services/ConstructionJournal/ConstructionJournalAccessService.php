<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\ProjectParticipantService;
use App\Services\Project\UserProjectAccessService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

final class ConstructionJournalAccessService
{
    public function __construct(
        private readonly ProjectParticipantService $participants,
        private readonly UserProjectAccessService $projects,
        private readonly AuthorizationService $authorization,
    ) {}

    public function canAccessProject(User $user, Project $project): bool
    {
        $organizationId = (int) $user->current_organization_id;

        return $organizationId > 0
            && $user->organizations()->where('organizations.id', $organizationId)
                ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists()
            && $project->hasOrganization($organizationId)
            && $this->projects->canAccessProject($user, $project, $organizationId);
    }

    public function hasPermission(User $user, Project $project, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->authorization->can($user, 'construction-journal.'.$permission, [
                'organization_id' => (int) $user->current_organization_id,
                'project_id' => (int) $project->id,
                'strict_project_scope' => true,
            ])) {
                return true;
            }
        }

        return false;
    }

    public function visiblePerformerIds(User $user, Project $project): array
    {
        if (! $this->canAccessProject($user, $project)) {
            return [];
        }

        $organizationId = (int) $user->current_organization_id;
        $hierarchy = $this->participants->getHierarchy($project);
        $visible = [$organizationId];
        if ($hierarchy['configured']) {
            do {
                $count = count($visible);
                foreach ($hierarchy['participants'] as $participant) {
                    if (in_array($participant['parent_organization_id'], $visible, true)
                        && ! in_array($participant['organization_id'], $visible, true)) {
                        $visible[] = $participant['organization_id'];
                    }
                }
            } while (count($visible) !== $count);
        }

        return Organization::query()->whereIn('id', $visible)->where('is_active', true)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function canRead(User $user, ConstructionJournal $journal): bool
    {
        return $journal->project !== null
            && in_array((int) $journal->performing_organization_id, $this->visiblePerformerIds($user, $journal->project), true)
            && $this->hasPermission($user, $journal->project, ['view', '*']);
    }

    public function canWrite(User $user, ConstructionJournal $journal, array $permissions = ['edit']): bool
    {
        return $journal->project !== null
            && (int) $journal->performing_organization_id === (int) $user->current_organization_id
            && $this->canAccessProject($user, $journal->project)
            && $this->hasPermission($user, $journal->project, $permissions);
    }

    public function assertReadable(User $user, ConstructionJournal $journal, array $permissions = ['view']): void
    {
        if (! $this->canRead($user, $journal)
            || ! $this->hasPermission($user, $journal->project, $permissions)) {
            throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
    }

    public function approvalOrganizationId(ConstructionJournal $journal): int
    {
        $project = $journal->project;
        if (! $project) {
            throw new DomainException(trans_message('construction_journal.errors.hierarchy_required'));
        }
        $hierarchy = $this->participants->getHierarchy($project);
        $performerId = (int) $journal->performing_organization_id;
        $activeIds = array_column($hierarchy['participants'], 'organization_id');
        if (! in_array($performerId, $activeIds, true)
            || ! Organization::query()->whereKey($performerId)->where('is_active', true)->exists()) {
            throw new DomainException(trans_message('construction_journal.errors.access_denied'));
        }
        if (Organization::query()->whereIn('id', $activeIds)->where('is_active', true)->count() !== count($activeIds)) {
            throw new DomainException(trans_message('construction_journal.errors.hierarchy_inactive_organization'));
        }
        if (count($activeIds) === 1) {
            return $performerId;
        }
        if (! $hierarchy['configured']) {
            throw new DomainException(trans_message('construction_journal.errors.hierarchy_required'));
        }
        foreach ($hierarchy['participants'] as $participant) {
            if ($participant['organization_id'] === $performerId) {
                return $participant['parent_organization_id'] ?? $performerId;
            }
        }

        throw new DomainException(trans_message('construction_journal.errors.hierarchy_required'));
    }

    public function approvalContext(ConstructionJournal $journal): array
    {
        try {
            $organizationId = $this->approvalOrganizationId($journal);

            return ['organization_id' => $organizationId, 'mode' => $organizationId === (int) $journal->performing_organization_id ? 'internal' : 'external', 'message' => null];
        } catch (DomainException $exception) {
            return ['organization_id' => null, 'mode' => 'unconfigured', 'message' => $exception->getMessage()];
        }
    }

    public function canApprove(User $user, ConstructionJournalEntry $entry): bool
    {
        $journal = $entry->journal;
        if (! $journal || ! $journal->project || ! $this->canAccessProject($user, $journal->project)) {
            return false;
        }
        try {
            $organizationId = $this->approvalOrganizationId($journal);
        } catch (DomainException) {
            return false;
        }
        if ($organizationId !== (int) $user->current_organization_id
            || ! $this->hasPermission($user, $journal->project, ['approve', '*'])) {
            return false;
        }
        if ((int) $entry->created_by_user_id === (int) $user->id) {
            return $organizationId === (int) $journal->performing_organization_id
                && ($user->isOrganizationOwner($organizationId)
                    || $user->organizations()->where('organizations.id', $organizationId)
                        ->wherePivot('is_owner', true)->wherePivot('is_active', true)->exists());
        }

        return true;
    }
}

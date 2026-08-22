<?php

namespace App\Policies;

use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;

class ConstructionJournalEntryPolicy
{
    private function hasProjectAccess(User $user, Project $project): bool
    {
        $organizationId = $user->current_organization_id;

        if (! $organizationId) {
            return false;
        }

        return $project->hasOrganization($organizationId);
    }

    private function hasJournalAccess(User $user, ConstructionJournal $journal): bool
    {
        return (int) $user->current_organization_id === (int) $journal->organization_id
            && $journal->project !== null
            && $this->hasProjectAccess($user, $journal->project);
    }

    private function hasModulePermission(User $user, array $permissions, ?Project $project = null): bool
    {
        $organizationId = $user->current_organization_id;

        if (! $organizationId) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($project && $user->hasPermission("construction-journal.{$permission}", [
                'organization_id' => $organizationId,
                'project_id' => $project->id,
            ])) {
                return true;
            }

            if ($user->hasPermission("construction-journal.{$permission}", [
                'organization_id' => $organizationId,
            ])) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, ConstructionJournalEntry $entry): bool
    {
        $project = $entry->journal?->project;

        if (! $project || ! $entry->journal || ! $this->hasJournalAccess($user, $entry->journal)) {
            return false;
        }

        return $this->hasModulePermission($user, ['view', '*'], $project);
    }

    public function create(User $user, ConstructionJournal $journal): bool
    {
        $project = $journal->project;

        if (! $project || ! $this->hasJournalAccess($user, $journal)) {
            return false;
        }

        if (! $journal->canBeEdited()) {
            return false;
        }

        return $this->hasModulePermission($user, ['create', '*'], $project);
    }

    public function update(User $user, ConstructionJournalEntry $entry): bool
    {
        $project = $entry->journal?->project;

        if (! $project || ! $entry->journal || ! $this->hasJournalAccess($user, $entry->journal)) {
            return false;
        }

        if ($entry->journal->status !== JournalStatusEnum::ACTIVE) {
            return false;
        }

        $isOwner = $entry->created_by_user_id === $user->id;
        $canEditAll = $this->hasModulePermission($user, ['edit_all', '*'], $project);

        if (! $isOwner && ! $canEditAll) {
            return false;
        }

        if (! $entry->canBeEdited()) {
            return false;
        }

        return $this->hasModulePermission($user, ['edit', '*'], $project);
    }

    public function delete(User $user, ConstructionJournalEntry $entry): bool
    {
        $project = $entry->journal?->project;

        if (! $project || ! $entry->journal || ! $this->hasJournalAccess($user, $entry->journal)) {
            return false;
        }

        if ($entry->journal->status !== JournalStatusEnum::ACTIVE) {
            return false;
        }

        $isOwner = $entry->created_by_user_id === $user->id;
        $canDeleteAll = $this->hasModulePermission($user, ['delete_all', '*'], $project);

        if (! $isOwner && ! $canDeleteAll) {
            return false;
        }

        if (! $entry->canBeEdited()) {
            return false;
        }

        return $this->hasModulePermission($user, ['delete', '*'], $project);
    }

    public function approve(User $user, ConstructionJournalEntry $entry): bool
    {
        $project = $entry->journal?->project;

        if (! $project || ! $entry->journal || ! $this->hasJournalAccess($user, $entry->journal)) {
            return false;
        }

        if ($entry->journal->status !== JournalStatusEnum::ACTIVE) {
            return false;
        }

        $journalOrganizationId = $entry->journal?->organization_id;

        if (
            $entry->created_by_user_id === $user->id
            && (! $journalOrganizationId || ! $this->isOrganizationOwner($user, (int) $journalOrganizationId))
        ) {
            return false;
        }

        return $this->hasModulePermission($user, ['approve', '*'], $project);
    }

    private function isOrganizationOwner(User $user, int $organizationId): bool
    {
        return $user->isOrganizationOwner($organizationId)
            || $user->organizations()
                ->where('organization_user.organization_id', $organizationId)
                ->wherePivot('is_owner', true)
                ->wherePivot('is_active', true)
                ->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;

class ConstructionJournalEntryPolicy
{
    private function hasProjectAccess(User $user, Project $project): bool
    {
        return app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canAccessProject($user, $project);
    }

    private function hasJournalAccess(User $user, ConstructionJournal $journal): bool
    {
        return app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canWrite($user, $journal, ['*', 'view', 'create', 'edit', 'delete', 'approve', 'reopen', 'export']);
    }

    private function hasModulePermission(User $user, array $permissions, ?Project $project = null): bool
    {
        return $project !== null && app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->hasPermission($user, $project, $permissions);
    }

    public function view(User $user, ConstructionJournalEntry $entry): bool
    {
        return $entry->journal !== null
            && app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canRead($user, $entry->journal);
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

        if ($entry->status !== JournalEntryStatusEnum::DRAFT || $this->hasApprovalEvents($entry)) {
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

        return $this->hasModulePermission($user, ['delete', '*'], $project);
    }

    private function hasApprovalEvents(ConstructionJournalEntry $entry): bool
    {
        if ($entry->relationLoaded('approvalEvents')) {
            return $entry->approvalEvents->isNotEmpty();
        }

        return $entry->approvalEvents()->exists();
    }

    public function approve(User $user, ConstructionJournalEntry $entry): bool
    {
        return $entry->journal?->status === JournalStatusEnum::ACTIVE
            && app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canApprove($user, $entry);
    }
}

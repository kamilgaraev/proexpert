<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\Project;
use App\Models\User;

class ConstructionJournalPolicy
{
    private function hasProjectAccess(User $user, Project $project): bool
    {
        return app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canAccessProject($user, $project);
    }

    private function hasJournalAccess(User $user, ConstructionJournal $journal): bool
    {
        return app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canWrite($user, $journal, ['*', 'view', 'create', 'edit', 'delete', 'approve', 'reopen', 'export']);
    }

    private function hasModulePermission(User $user, array $permissions, ?int $organizationId = null, ?int $projectId = null): bool
    {
        $project = $projectId ? Project::query()->find($projectId) : null;
        return $project !== null && app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->hasPermission($user, $project, $permissions);
    }

    public function viewAny(User $user, Project $project): bool
    {
        if (! $this->hasProjectAccess($user, $project)) {
            return false;
        }

        return $this->hasModulePermission($user, ['view', '*'], null, $project->id);
    }

    public function view(User $user, Project|ConstructionJournal $model): bool
    {
        if ($model instanceof Project) {
            if (! $this->hasProjectAccess($user, $model)) {
                return false;
            }

            return $this->hasModulePermission($user, ['view', '*'], null, $model->id);
        }

        $project = $model->project;

        if (! $project || ! app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canRead($user, $model)) {
            return false;
        }

        return $this->hasModulePermission($user, ['view', '*'], null, $project->id);
    }

    public function create(User $user, Project $project): bool
    {
        if (! $this->hasProjectAccess($user, $project)) {
            return false;
        }

        return $this->hasModulePermission($user, ['create', '*'], null, $project->id);
    }

    public function update(User $user, ConstructionJournal $journal): bool
    {
        $project = $journal->project;

        if (! $project || ! $this->hasJournalAccess($user, $journal)) {
            return false;
        }

        if (! $journal->canBeEdited()) {
            return false;
        }

        return $this->hasModulePermission($user, ['edit', '*'], null, $project->id);
    }

    public function delete(User $user, ConstructionJournal $journal): bool
    {
        $project = $journal->project;

        if (! $project || ! $this->hasJournalAccess($user, $journal)) {
            return false;
        }

        return $this->hasModulePermission($user, ['delete', '*'], null, $project->id);
    }

    public function close(User $user, ConstructionJournal $journal): bool
    {
        return $journal->status === JournalStatusEnum::ACTIVE
            && $this->canManageLifecycle($user, $journal, ['edit', '*']);
    }

    public function archive(User $user, ConstructionJournal $journal): bool
    {
        return $journal->status === JournalStatusEnum::CLOSED
            && $this->canManageLifecycle($user, $journal, ['edit', '*']);
    }

    public function reopen(User $user, ConstructionJournal $journal): bool
    {
        return $journal->status === JournalStatusEnum::CLOSED
            && $this->canManageLifecycle($user, $journal, ['reopen', '*']);
    }

    public function export(User $user, ConstructionJournal $journal): bool
    {
        return app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canRead($user, $journal)
            && $this->hasModulePermission($user, ['export', '*'], null, $journal->project_id);
    }

    private function canManageLifecycle(User $user, ConstructionJournal $journal, array $permissions): bool
    {
        $project = $journal->project;

        if (! $project || ! $this->hasJournalAccess($user, $journal)) {
            return false;
        }

        return $this->hasModulePermission($user, $permissions, null, $project->id);
    }
}

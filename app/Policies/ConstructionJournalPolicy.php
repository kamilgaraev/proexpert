<?php

namespace App\Policies;

use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\Project;
use App\Models\User;

class ConstructionJournalPolicy
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

    private function hasModulePermission(
        User $user,
        array $permissions,
        ?int $organizationId = null,
        ?int $projectId = null
    ): bool {
        $orgId = $organizationId ?? $user->current_organization_id;

        if (! $orgId) {
            return false;
        }

        $orgContext = ['organization_id' => $orgId];

        foreach ($permissions as $permission) {
            if ($user->hasPermission("construction-journal.{$permission}", $orgContext)) {
                return true;
            }
        }

        if ($projectId) {
            $projectContext = ['project_id' => $projectId, 'organization_id' => $orgId];

            foreach ($permissions as $permission) {
                if ($user->hasPermission("construction-journal.{$permission}", $projectContext)) {
                    return true;
                }
            }
        }

        return false;
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

        if (! $project || ! $this->hasJournalAccess($user, $model)) {
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
        $project = $journal->project;

        if (! $project || ! $this->hasJournalAccess($user, $journal)) {
            return false;
        }

        return $this->hasModulePermission($user, ['export', '*'], null, $project->id);
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

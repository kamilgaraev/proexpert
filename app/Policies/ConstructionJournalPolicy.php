<?php

namespace App\Policies;

use App\Models\ConstructionJournal;
use App\Models\Project;
use App\Models\User;

class ConstructionJournalPolicy
{
    private function hasProjectAccess(User $user, Project $project): bool
    {
        $organizationId = $user->current_organization_id;

        if (!$organizationId) {
            return false;
        }

        return $project->hasOrganization($organizationId);
    }

    private function hasModulePermission(
        User $user,
        array $permissions,
        ?int $organizationId = null,
        ?int $projectId = null
    ): bool {
        $orgId = $organizationId ?? $user->current_organization_id;

        if (!$orgId) {
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
        if (!$this->hasProjectAccess($user, $project)) {
            return false;
        }

        return $this->hasModulePermission($user, ['view', '*'], $project->organization_id, $project->id);
    }

    public function view(User $user, Project|ConstructionJournal $model): bool
    {
        if ($model instanceof Project) {
            if (!$this->hasProjectAccess($user, $model)) {
                return false;
            }

            return $this->hasModulePermission($user, ['view', '*'], $model->organization_id, $model->id);
        }

        $project = $model->project;

        if (!$project || !$this->hasProjectAccess($user, $project)) {
            return false;
        }

        return $this->hasModulePermission($user, ['view', '*'], $project->organization_id, $project->id);
    }

    public function create(User $user, Project $project): bool
    {
        if (!$this->hasProjectAccess($user, $project)) {
            return false;
        }

        return $this->hasModulePermission($user, ['create', '*'], $project->organization_id, $project->id);
    }

    public function update(User $user, ConstructionJournal $journal): bool
    {
        $project = $journal->project;

        if (!$project || !$this->hasProjectAccess($user, $project)) {
            return false;
        }

        if (!$journal->canBeEdited()) {
            return false;
        }

        return $this->hasModulePermission($user, ['edit', '*'], $project->organization_id, $project->id);
    }

    public function delete(User $user, ConstructionJournal $journal): bool
    {
        $project = $journal->project;

        if (!$project || !$this->hasProjectAccess($user, $project)) {
            return false;
        }

        return $this->hasModulePermission($user, ['delete', '*'], $project->organization_id, $project->id);
    }

    public function export(User $user, ConstructionJournal $journal): bool
    {
        $project = $journal->project;

        if (!$project || !$this->hasProjectAccess($user, $project)) {
            return false;
        }

        return $this->hasModulePermission($user, ['export', '*'], $project->organization_id, $project->id);
    }
}

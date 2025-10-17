<?php

namespace App\Listeners;

use App\Events\ProjectOrganizationAdded;
use App\Events\ProjectOrganizationRoleChanged;
use App\Events\ProjectOrganizationRemoved;
use Illuminate\Support\Facades\Log;

class LogProjectOrganizationActivity
{
    /**
     * Handle the event for organization added to project
     */
    public function handleAdded(ProjectOrganizationAdded $event): void
    {
        Log::info('[RBAC] Organization added to project', [
            'event' => 'ProjectOrganizationAdded',
            'project_id' => $event->project->id,
            'project_name' => $event->project->name,
            'organization_id' => $event->organization->id,
            'organization_name' => $event->organization->name,
            'role' => $event->role->value,
            'added_by_user_id' => $event->addedBy?->id,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Handle the event for organization role changed
     */
    public function handleRoleChanged(ProjectOrganizationRoleChanged $event): void
    {
        Log::info('[RBAC] Organization role changed in project', [
            'event' => 'ProjectOrganizationRoleChanged',
            'project_id' => $event->project->id,
            'project_name' => $event->project->name,
            'organization_id' => $event->organization->id,
            'organization_name' => $event->organization->name,
            'old_role' => $event->oldRole->value,
            'new_role' => $event->newRole->value,
            'changed_by_user_id' => $event->changedBy?->id,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Handle the event for organization removed from project
     */
    public function handleRemoved(ProjectOrganizationRemoved $event): void
    {
        Log::info('[RBAC] Organization removed from project', [
            'event' => 'ProjectOrganizationRemoved',
            'project_id' => $event->project->id,
            'project_name' => $event->project->name,
            'organization_id' => $event->organization->id,
            'organization_name' => $event->organization->name,
            'role' => $event->role->value,
            'removed_by_user_id' => $event->removedBy?->id,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

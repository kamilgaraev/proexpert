<?php

namespace App\Listeners;

use App\Events\ProjectOrganizationAdded;
use App\Events\ProjectOrganizationRoleChanged;
use App\Events\ProjectOrganizationRemoved;
use App\Services\Project\ProjectContextService;
use Illuminate\Support\Facades\Log;

class InvalidateProjectContextCache
{
    protected ProjectContextService $projectContextService;

    public function __construct(ProjectContextService $projectContextService)
    {
        $this->projectContextService = $projectContextService;
    }

    /**
     * Handle organization added event
     */
    public function handleAdded(ProjectOrganizationAdded $event): void
    {
        $this->invalidate($event->project->id, $event->organization->id);
    }

    /**
     * Handle role changed event
     */
    public function handleRoleChanged(ProjectOrganizationRoleChanged $event): void
    {
        $this->invalidate($event->project->id, $event->organization->id);
    }

    /**
     * Handle organization removed event
     */
    public function handleRemoved(ProjectOrganizationRemoved $event): void
    {
        $this->invalidate($event->project->id, $event->organization->id);
    }

    /**
     * Invalidate cache for project context
     */
    private function invalidate(int $projectId, int $organizationId): void
    {
        try {
            $this->projectContextService->invalidateContext($projectId, $organizationId);
            
            Log::debug('[Cache] Project context invalidated', [
                'project_id' => $projectId,
                'organization_id' => $organizationId,
            ]);
        } catch (\Exception $e) {
            Log::error('[Cache] Failed to invalidate project context', [
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

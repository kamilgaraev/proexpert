<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns;

use App\Domain\Authorization\Services\AuthorizationService;

trait AuthorizesEstimateGenerationRequest
{
    private function authorizeEstimateGeneration(string $permission): bool
    {
        $user = $this->user();
        $organizationId = $user?->current_organization_id;
        $project = $this->route('project');
        $projectId = is_object($project) && method_exists($project, 'getKey') ? $project->getKey() : $project;

        return $user !== null && $organizationId !== null && is_numeric($projectId)
            && app(AuthorizationService::class)->can($user, $permission, [
                'organization_id' => (int) $organizationId,
                'project_id' => (int) $projectId,
            ]);
    }
}

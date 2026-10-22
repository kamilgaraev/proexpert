<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\Models\Organization;
use App\Models\User;

final readonly class OrdinaryAssistantSourceCatalog
{
    public function __construct(private AIToolRegistry $tools, private AIPermissionChecker $permissions,
        private OrdinaryAssistantSourceProjection $projection) {}

    public function capture(User $actor, int $organizationId, int $projectId, string $name, array $arguments): array
    {
        $arguments = $this->projection->arguments($name, $arguments, $projectId);
        if ((int) $actor->current_organization_id !== $organizationId || ! $actor->is_active
            || ! $this->permissions->canExposeTool($actor, $name) || ! $this->permissions->canExecuteTool($actor, $name, $arguments)) {
            throw new AssistantEgressDenied('access_denied');
        }
        $tool = $this->tools->getTool($name);
        $organization = Organization::query()->find($organizationId);
        if ($tool === null || $organization === null) {
            throw new AssistantEgressDenied('source_unknown');
        }
        $result = $this->projection->project($name, $tool->execute($arguments, $actor, $organization), $organizationId, (int) $actor->id, $projectId);
        $provider = $this->projection->forProvider($result);

        return ['proof' => ['tool' => $name, 'arguments' => $arguments,
            'projection_version' => OrdinaryAssistantSourceProjection::VERSION,
            'source_versions' => array_column($result['source_refs'], 'source_version'),
            'projection_hash' => OrdinaryAssistantSourceProjection::hash($provider)], 'preview' => $provider];
    }
}

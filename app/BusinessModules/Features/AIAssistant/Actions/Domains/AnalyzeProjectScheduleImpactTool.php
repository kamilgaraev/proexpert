<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectScheduleImpactReader;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class AnalyzeProjectScheduleImpactTool implements AIToolInterface
{
    public function __construct(private AssistantProjectScheduleImpactReader $reader, private AIPermissionChecker $permissions) {}
    public function getName(): string { return 'analyze_project_schedule_impact'; }
    public function getDescription(): string { return trans_message('ai_assistant_decisions.schedule_description'); }
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1],
            'focus' => ['type' => 'string', 'enum' => ['overdue_impact', 'critical_path', 'dependencies']]], 'required' => ['project_id', 'focus'], 'additionalProperties' => false];
    }
    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! $this->permissions->canExecuteTool($user, $this->getName(), $arguments)) { throw new AccessDeniedHttpException; }
        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}

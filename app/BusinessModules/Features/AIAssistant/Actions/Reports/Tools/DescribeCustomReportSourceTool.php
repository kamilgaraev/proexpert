<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class DescribeCustomReportSourceTool implements AIToolInterface
{
    public function __construct(private AssistantCustomReportSource $sources, private AssistantDataAccessPolicy $policy,
        private AIPermissionChecker $permissions) {}

    public function getName(): string
    {
        return 'describe_custom_report_source';
    }

    public function getDescription(): string
    {
        return trans_message('ai_custom_reports.source_description');
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['domain' => ['type' => 'string'], 'entity_type' => ['type' => 'string'],
            'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1]], 'required' => ['domain', 'entity_type', 'project_id'], 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! $this->permissions->canExecuteTool($user, $this->getName(), $arguments)) {
            throw new AccessDeniedHttpException;
        }

        return $this->policy->withCurrentChecks($user, (int) $organization->id, function () use ($arguments, $user, $organization): array {
            $source = $this->sources->resolve($user, (int) $organization->id, (string) $arguments['domain'], (string) $arguments['entity_type'],
                isset($arguments['project_id']) ? (int) $arguments['project_id'] : null);

            return ['status' => 'success', 'domain' => $arguments['domain'], 'entity_type' => $arguments['entity_type'],
                'fields' => $source['fields'], 'maximum_source_rows' => 1000, 'formats' => ['xlsx', 'csv', 'pdf']];
        }, true);
    }
}

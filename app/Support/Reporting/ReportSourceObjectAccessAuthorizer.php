<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;

final readonly class ReportSourceObjectAccessAuthorizer
{
    private const KIND_ALIASES = [
        'schedule_task' => 'task',
        'task_dependency' => 'dependency',
        'performance_act' => 'act',
        'contract_performance_act' => 'act',
        'performance_act_line' => 'act_line',
        'completed_work' => 'work',
        'work_constraint' => 'constraint',
        'wip_forecast_line' => 'wip',
        'project_control_baseline' => 'baseline',
    ];

    public function assertAccessible(
        ReportExecutionContext $context,
        string $sourceKind,
        int $sourceId,
        int $projectId,
    ): void {
        $canonicalKind = self::KIND_ALIASES[$sourceKind] ?? $sourceKind;
        if ($sourceId < 1
            || $projectId < 1
            || ! in_array($projectId, $context->authorization->projectIds, true)
            || ! in_array($projectId, $context->scope->projectIds, true)
        ) {
            $this->deny();
        }

        $this->assertResourceSet($context->authorization->resources, $canonicalKind, $sourceId, $projectId);
        $this->assertResourceSet($context->scope->resources, $canonicalKind, $sourceId, $projectId);
    }

    private function assertResourceSet(
        array $resources,
        string $canonicalKind,
        int $sourceId,
        int $projectId,
    ): void {
        $restrictedIds = [];
        foreach ($resources as $resource) {
            if (! $resource instanceof ReportScopedResource) {
                $this->deny();
            }
            $resourceKind = self::KIND_ALIASES[$resource->kind] ?? $resource->kind;
            if ($resourceKind === $canonicalKind
                && ($resource->projectId === null || $resource->projectId === $projectId)
            ) {
                $restrictedIds[$resource->id] = true;
            }
        }
        if ($restrictedIds !== [] && ! isset($restrictedIds[$sourceId])) {
            $this->deny();
        }
    }

    private function deny(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
    }
}

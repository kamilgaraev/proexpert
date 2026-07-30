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

    public function assertReferencesAccessible(
        ReportExecutionContext $context,
        array $references,
        ?int $defaultProjectId = null,
    ): void {
        if (! array_is_list($references) || $references === []) {
            $this->deny();
        }

        foreach ($references as $reference) {
            if (! is_array($reference) || array_is_list($reference)) {
                $this->deny();
            }
            $sourceKind = $reference['type'] ?? null;
            if (! is_string($sourceKind) || trim($sourceKind) === '') {
                $this->deny();
            }
            if ($sourceKind === 'waiver_evidence') {
                if (! is_string($reference['id'] ?? null) || trim((string) $reference['id']) === '') {
                    $this->deny();
                }

                continue;
            }

            $projectId = $reference['project_id'] ?? $defaultProjectId;
            if (! is_numeric($projectId) || (int) $projectId < 1) {
                $this->deny();
            }
            $sourceIds = array_key_exists('ids', $reference)
                ? $reference['ids']
                : [$reference['id'] ?? null];
            if (! is_array($sourceIds) || ! array_is_list($sourceIds) || $sourceIds === []) {
                $this->deny();
            }
            foreach ($sourceIds as $sourceId) {
                if (! is_numeric($sourceId) || (int) $sourceId < 1) {
                    $this->deny();
                }
                $this->assertAccessible($context, $sourceKind, (int) $sourceId, (int) $projectId);
            }
        }
    }

    private function assertResourceSet(
        array $resources,
        string $canonicalKind,
        int $sourceId,
        int $projectId,
    ): void {
        $restrictedIds = [];
        $restricted = false;
        foreach ($resources as $resource) {
            if (! $resource instanceof ReportScopedResource) {
                $this->deny();
            }
            $resourceKind = self::KIND_ALIASES[$resource->kind] ?? $resource->kind;
            if ($resourceKind !== $canonicalKind) {
                continue;
            }
            $restricted = true;
            if ($resource->projectId === null || $resource->projectId === $projectId) {
                $restrictedIds[$resource->id] = true;
            }
        }
        if ($restricted && ! isset($restrictedIds[$sourceId])) {
            $this->deny();
        }
    }

    private function deny(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
    }
}

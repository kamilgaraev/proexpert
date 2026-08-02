<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;

final readonly class ReportScopedResourceFilter
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

    public function ids(ReportScope $scope, array $kinds, array $projectIds): ?array
    {
        $kindSet = array_fill_keys(array_map($this->canonicalKind(...), $kinds), true);
        $projectSet = array_fill_keys($projectIds, true);
        $ids = [];
        $restricted = false;

        foreach ($scope->resources as $resource) {
            if (! $resource instanceof ReportScopedResource
                || ! isset($kindSet[$this->canonicalKind($resource->kind)])
            ) {
                continue;
            }
            $restricted = true;
            if ($resource->projectId !== null && ! isset($projectSet[$resource->projectId])) {
                continue;
            }

            $ids[$resource->id] = true;
        }

        if (! $restricted) {
            return null;
        }

        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    public function allowsReferences(
        ReportScope $scope,
        int $projectId,
        array $references,
        array $applicableKinds,
    ): bool {
        if ($projectId < 1 || ! in_array($projectId, $scope->projectIds, true)) {
            return false;
        }

        $applicable = array_fill_keys(array_map($this->canonicalKind(...), $applicableKinds), true);
        $restrictions = [];
        foreach ($scope->resources as $resource) {
            if (! $resource instanceof ReportScopedResource) {
                return false;
            }
            $kind = $this->canonicalKind($resource->kind);
            if (! isset($applicable[$kind])) {
                continue;
            }
            $restrictions[$kind] ??= [];
            if ($resource->projectId === null || $resource->projectId === $projectId) {
                $restrictions[$kind][$resource->id] = true;
            }
        }
        if ($restrictions === []) {
            return true;
        }

        $matched = [];
        foreach ($references as $reference) {
            if (! is_array($reference)
                || ! is_string($reference['type'] ?? null)
                || ! is_numeric($reference['id'] ?? null)
                || (int) $reference['id'] < 1
            ) {
                continue;
            }
            $referenceProjectId = $reference['project_id'] ?? $projectId;
            if (! is_numeric($referenceProjectId) || (int) $referenceProjectId !== $projectId) {
                continue;
            }
            $kind = $this->canonicalKind($reference['type']);
            if (isset($restrictions[$kind][(int) $reference['id']])) {
                $matched[$kind] = true;
            }
        }

        foreach ($restrictions as $kind => $ids) {
            if ($ids === [] || ! isset($matched[$kind])) {
                return false;
            }
        }

        return true;
    }

    public function canonicalKind(string $kind): string
    {
        return self::KIND_ALIASES[$kind] ?? $kind;
    }
}

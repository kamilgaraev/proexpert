<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\Support\Reporting\ReportScopedResourceFilter;
use InvalidArgumentException;

final readonly class LookaheadResourceScope
{
    private ReportScopedResourceFilter $resources;

    public function __construct(?ReportScopedResourceFilter $resources = null)
    {
        $this->resources = $resources ?? new ReportScopedResourceFilter;
    }

    public function filterConstraints(
        ReportScope $scope,
        int $projectId,
        int $scheduleId,
        int $taskId,
        array $constraints,
    ): ?array {
        $allowed = [];
        foreach ($constraints as $constraint) {
            if (! $constraint instanceof LookaheadConstraintState) {
                throw new InvalidArgumentException('lookahead_resource_scope_invalid');
            }
            if ($this->allowsConstraintIdentity(
                $scope,
                $projectId,
                $scheduleId,
                $taskId,
                $constraint->constraintId,
                $constraint->linkedResourceType,
                $constraint->linkedResourceId,
            )) {
                $allowed[] = $constraint;
            }
        }
        if ($allowed !== []) {
            return $allowed;
        }

        return $this->allowsTask($scope, $projectId, $scheduleId, $taskId) ? [] : null;
    }

    public function allowsTask(
        ReportScope $scope,
        int $projectId,
        int $scheduleId,
        int $taskId,
    ): bool {
        return $this->resources->allowsReferences(
            $scope,
            $projectId,
            $this->baseReferences($projectId, $scheduleId, $taskId),
            $this->applicableKinds($scope),
        );
    }

    public function allowsConstraintIdentity(
        ReportScope $scope,
        int $projectId,
        int $scheduleId,
        int $taskId,
        int $constraintId,
        ?string $linkedResourceType,
        ?int $linkedResourceId,
    ): bool {
        $references = [
            ...$this->baseReferences($projectId, $scheduleId, $taskId),
            [
                'type' => 'work_constraint',
                'id' => $constraintId,
                'project_id' => $projectId,
            ],
        ];
        if ($linkedResourceType !== null && $linkedResourceId !== null) {
            $references[] = [
                'type' => $linkedResourceType,
                'id' => $linkedResourceId,
                'project_id' => $projectId,
            ];
        }

        return $this->resources->allowsReferences(
            $scope,
            $projectId,
            $references,
            $this->applicableKinds($scope),
        );
    }

    public function requiresConstraintMatch(ReportScope $scope): bool
    {
        foreach ($scope->resources as $resource) {
            if (! $resource instanceof ReportScopedResource) {
                throw new InvalidArgumentException('lookahead_resource_scope_invalid');
            }
            if (! in_array(
                $this->resources->canonicalKind($resource->kind),
                ['project', 'schedule', 'task'],
                true,
            )) {
                return true;
            }
        }

        return false;
    }

    private function baseReferences(int $projectId, int $scheduleId, int $taskId): array
    {
        return [
            ['type' => 'schedule_task', 'id' => $taskId, 'project_id' => $projectId],
            ['type' => 'schedule', 'id' => $scheduleId, 'project_id' => $projectId],
        ];
    }

    private function applicableKinds(ReportScope $scope): array
    {
        $kinds = ['task', 'schedule_task', 'schedule', 'constraint', 'work_constraint'];
        foreach ($scope->resources as $resource) {
            if (! $resource instanceof ReportScopedResource) {
                throw new InvalidArgumentException('lookahead_resource_scope_invalid');
            }
            $kinds[] = $resource->kind;
        }

        return $kinds;
    }
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Queries;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadResourceScope;
use App\Support\Reporting\ReportScopedResourceFilter;
use DateTimeImmutable;

final readonly class LookaheadResourceCandidateQuery
{
    public function __construct(
        private ReportScopedResourceFilter $resourceFilter,
        private LookaheadResourceScope $resourceScope,
    ) {}

    public function taskIds(
        ReportScope $scope,
        array $projectIds,
        ?array $scheduleIds,
        DateTimeImmutable $asOf,
    ): ?array {
        if (! $this->resourceScope->requiresConstraintMatch($scope)) {
            return null;
        }
        $scopedConstraintIds = $this->resourceFilter->ids(
            $scope,
            ['constraint', 'work_constraint'],
            $projectIds,
        );
        $constraints = WorkConstraint::withTrashed()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->when(
                $scheduleIds !== null,
                static fn ($builder) => $builder->whereIn('schedule_id', $scheduleIds),
            )
            ->where('created_at', '<=', $asOf)
            ->when(
                $scopedConstraintIds !== null,
                static fn ($builder) => $builder->whereIn('id', $scopedConstraintIds),
            )
            ->orderBy('id');
        $taskIds = [];
        $constraints->chunkById(500, function ($constraintPage) use (
            $scope,
            $asOf,
            &$taskIds,
        ): void {
            $constraintIds = [];
            foreach ($constraintPage as $constraint) {
                $constraintIds[] = (int) $constraint->id;
            }
            $events = [];
            foreach (WorkConstraintTransitionEvent::query()
                ->where('organization_id', $scope->organizationId)
                ->whereIn('constraint_id', $constraintIds)
                ->where('occurred_at', '<=', $asOf)
                ->whereRaw(
                    'NOT EXISTS (
                        SELECT 1 FROM lookahead_reporting_constraint_transition_events later
                        WHERE later.organization_id = lookahead_reporting_constraint_transition_events.organization_id
                          AND later.constraint_id = lookahead_reporting_constraint_transition_events.constraint_id
                          AND later.occurred_at <= ?
                          AND later.event_version > lookahead_reporting_constraint_transition_events.event_version
                    )',
                    [$asOf->format(DATE_ATOM)],
                )
                ->lazyById(500) as $event) {
                $events[(int) $event->constraint_id] = $event;
            }
            foreach ($constraintPage as $constraint) {
                $latest = $events[(int) $constraint->id] ?? null;
                $linked = $latest instanceof WorkConstraintTransitionEvent
                    ? $this->linkedFromEvidence((array) $latest->evidence_refs)
                    : $this->linkedFromConstraint($constraint);
                if ($this->resourceScope->allowsConstraintIdentity(
                    $scope,
                    (int) $constraint->project_id,
                    (int) $constraint->schedule_id,
                    (int) $constraint->schedule_task_id,
                    (int) $constraint->id,
                    $linked['type'] ?? null,
                    $linked['id'] ?? null,
                )) {
                    $taskIds[(int) $constraint->schedule_task_id] = true;
                }
            }
        });

        $result = array_map('intval', array_keys($taskIds));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    private function linkedFromEvidence(array $references): ?array
    {
        foreach ($references as $reference) {
            if (! is_array($reference)
                || ($reference['type'] ?? null) === 'waiver_evidence'
                || ! is_string($reference['type'] ?? null)
                || ! is_numeric($reference['id'] ?? null)
                || (int) $reference['id'] < 1
            ) {
                continue;
            }

            return ['type' => $reference['type'], 'id' => (int) $reference['id']];
        }

        return null;
    }

    private function linkedFromConstraint(WorkConstraint $constraint): ?array
    {
        $metadata = (array) $constraint->metadata;
        $linked = $metadata['linked_action'] ?? $metadata['linked_entity'] ?? null;
        if (! is_array($linked)
            || ! is_string($linked['type'] ?? null)
            || ! is_numeric($linked['id'] ?? null)
            || (int) $linked['id'] < 1
        ) {
            return null;
        }

        return ['type' => $linked['type'], 'id' => (int) $linked['id']];
    }
}

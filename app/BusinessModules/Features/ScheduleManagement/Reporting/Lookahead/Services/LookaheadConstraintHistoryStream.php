<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use DateTimeImmutable;
use Generator;

final readonly class LookaheadConstraintHistoryStream
{
    public function states(
        ReportScope $scope,
        array $taskIds,
        ?array $constraintIds,
        DateTimeImmutable $asOf,
    ): Generator {
        $events = WorkConstraintTransitionEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('task_id', $taskIds)
            ->when(
                $constraintIds !== null,
                static fn ($builder) => $builder->whereIn('constraint_id', $constraintIds),
            )
            ->where('occurred_at', '<=', $asOf)
            ->orderBy('constraint_id')
            ->orderBy('event_version')
            ->orderBy('id')
            ->cursor();
        $constraintId = null;
        $first = null;
        $latest = null;
        $lineage = [];
        foreach ($events as $event) {
            if ($constraintId !== null && $constraintId !== (int) $event->constraint_id) {
                if (! $first instanceof WorkConstraintTransitionEvent
                    || ! $latest instanceof WorkConstraintTransitionEvent
                ) {
                    throw new \InvalidArgumentException('lookahead_constraint_history_invalid');
                }
                yield [
                    'task_id' => (int) $latest->task_id,
                    'state' => $this->state($first, $latest, $lineage),
                ];
                $lineage = [];
                $first = null;
            }
            $constraintId = (int) $event->constraint_id;
            $first ??= $event;
            $latest = $event;
            $lineage[] = [
                'id' => (int) $event->id,
                'version' => (int) $event->event_version,
                'source_hash' => (string) $event->source_hash,
            ];
        }
        if ($latest instanceof WorkConstraintTransitionEvent) {
            if (! $first instanceof WorkConstraintTransitionEvent) {
                throw new \InvalidArgumentException('lookahead_constraint_history_invalid');
            }
            yield [
                'task_id' => (int) $latest->task_id,
                'state' => $this->state($first, $latest, $lineage),
            ];
        }
    }

    private function state(
        WorkConstraintTransitionEvent $first,
        WorkConstraintTransitionEvent $latest,
        array $lineage,
    ): LookaheadConstraintState {
        $linked = $this->linkedResource((array) $latest->evidence_refs);

        return new LookaheadConstraintState(
            constraintId: (int) $latest->constraint_id,
            type: (string) $latest->constraint_type,
            severity: (string) $latest->severity,
            status: (string) $latest->to_status,
            waiverUntil: $latest->waiver_until === null
                ? null
                : new DateTimeImmutable($latest->waiver_until->format(DATE_ATOM)),
            waiverEvidenceRef: $latest->waiver_evidence_ref,
            openedAt: new DateTimeImmutable($first->occurred_at->format(DATE_ATOM)),
            linkedResourceType: $linked['type'] ?? null,
            linkedResourceId: $linked['id'] ?? null,
            transitionLineage: $lineage,
        );
    }

    private function linkedResource(array $evidenceRefs): ?array
    {
        foreach ($evidenceRefs as $reference) {
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
}

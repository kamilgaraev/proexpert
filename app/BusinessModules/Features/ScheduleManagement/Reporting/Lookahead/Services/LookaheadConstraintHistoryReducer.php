<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\Support\Reporting\CanonicalLineageAccumulator;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final class LookaheadConstraintHistoryReducer
{
    private ?WorkConstraintTransitionEvent $first = null;

    private ?WorkConstraintTransitionEvent $latest = null;

    private CanonicalLineageAccumulator $lineage;

    public function __construct()
    {
        $this->lineage = new CanonicalLineageAccumulator;
    }

    public function append(WorkConstraintTransitionEvent $event): void
    {
        if ((int) $event->id < 1
            || (int) $event->event_version < 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) $event->source_hash) !== 1
            || ($this->first !== null && $this->key($event) !== $this->key($this->first))
        ) {
            throw new InvalidArgumentException('lookahead_constraint_history_invalid');
        }

        $this->lineage->append(
            (int) $event->event_version,
            (int) $event->id,
            [
                'id' => (int) $event->id,
                'source_hash' => (string) $event->source_hash,
                'version' => (int) $event->event_version,
            ],
        );
        $this->first ??= $event;
        $this->latest = $event;
    }

    public function taskId(): int
    {
        return (int) ($this->latest?->task_id
            ?? throw new LogicException('lookahead_constraint_history_invalid'));
    }

    public function finish(): LookaheadConstraintState
    {
        $first = $this->first
            ?? throw new LogicException('lookahead_constraint_history_invalid');
        $latest = $this->latest
            ?? throw new LogicException('lookahead_constraint_history_invalid');
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
            lineage: $this->lineage->finish(),
        );
    }

    private function key(WorkConstraintTransitionEvent $event): array
    {
        return [
            (int) $event->organization_id,
            (int) $event->project_id,
            (int) $event->schedule_id,
            (int) $event->task_id,
            (int) $event->constraint_id,
        ];
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

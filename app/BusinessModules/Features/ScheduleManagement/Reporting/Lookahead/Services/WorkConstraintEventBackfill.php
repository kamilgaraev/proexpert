<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkConstraintEventBackfill
{
    public function __construct(private WorkConstraintEventRecorder $events)
    {
    }

    public function run(int $organizationId, array $projectIds): array
    {
        if ($organizationId < 1 || $projectIds === []) {
            throw new InvalidArgumentException('lookahead_constraint_backfill_scope_invalid');
        }

        $captured = [];
        $constraints = WorkConstraint::query()
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->orderBy('id')
            ->get();
        foreach ($constraints as $constraint) {
            $exists = WorkConstraintTransitionEvent::query()
                ->where('organization_id', $organizationId)
                ->where('constraint_id', $constraint->id)
                ->orderByDesc('event_version')
                ->first();
            if ($exists !== null) {
                $linked = (array) (($constraint->metadata['linked_action'] ?? null)
                    ?? ($constraint->metadata['linked_entity'] ?? null));
                if ($linked === []) {
                    continue;
                }
                $alreadyPinned = collect((array) $exists->evidence_refs)->contains(
                    static fn ($reference): bool => is_array($reference)
                        && ($reference['type'] ?? null) === ($linked['type'] ?? null)
                        && (string) ($reference['id'] ?? '') === (string) ($linked['id'] ?? ''),
                );
                if ($alreadyPinned) {
                    continue;
                }
                if ((string) $constraint->status !== 'open'
                    || !is_string($linked['created_at'] ?? null)
                    || trim($linked['created_at']) === ''
                ) {
                    throw new InvalidArgumentException('lookahead_constraint_link_history_unproven');
                }
                $captured[] = (int) $this->events->pinLinkedEvidence(
                    $constraint,
                    isset($linked['created_by_user_id']) ? (int) $linked['created_by_user_id'] : null,
                    new DateTimeImmutable($linked['created_at']),
                )->id;
                continue;
            }
            if ($constraint->created_at === null
                || $constraint->overridden_at !== null
                || (string) $constraint->status !== 'open'
            ) {
                throw new InvalidArgumentException('lookahead_constraint_backfill_history_unproven');
            }
            $captured[] = (int) $this->events->record(
                $constraint,
                null,
                (string) $constraint->status,
                $constraint->created_by_user_id === null ? null : (int) $constraint->created_by_user_id,
                new DateTimeImmutable($constraint->created_at->format(DATE_ATOM)),
            )->id;
        }

        return $captured;
    }
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class WorkConstraintEventRecorder
{
    public function pinLinkedEvidence(
        WorkConstraint $constraint,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): WorkConstraintTransitionEvent {
        $latest = WorkConstraintTransitionEvent::query()
            ->where('organization_id', $constraint->organization_id)
            ->where('constraint_id', $constraint->id)
            ->orderByDesc('event_version')
            ->first();
        $status = $latest === null ? (string) $constraint->status : (string) $latest->to_status;

        return $this->record(
            $constraint,
            $status,
            $status,
            $actorId,
            $occurredAt,
            $latest?->waiver_until === null
                ? null
                : new DateTimeImmutable($latest->waiver_until->format(DATE_ATOM)),
            $latest?->waiver_evidence_ref,
        );
    }

    public function record(
        WorkConstraint $constraint,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
        ?DateTimeImmutable $waiverUntil = null,
        ?string $waiverEvidenceRef = null,
    ): WorkConstraintTransitionEvent {
        if ((int) $constraint->organization_id < 1
            || (int) $constraint->project_id < 1
            || (int) $constraint->schedule_id < 1
            || (int) $constraint->schedule_task_id < 1
            || trim($toStatus) === ''
        ) {
            throw new InvalidArgumentException('work_constraint_event_scope_invalid');
        }
        $linkedResource = $this->linkedResource($constraint);

        $sourceHash = hash('sha256', CanonicalJson::encode([
            'actor_id' => $actorId,
            'constraint_id' => (int) $constraint->id,
            'constraint_type' => (string) $constraint->constraint_type,
            'from_status' => $fromStatus,
            'occurred_at' => $occurredAt->format(DATE_ATOM),
            'severity' => (string) $constraint->severity,
            'to_status' => $toStatus,
            'linked_resource' => $linkedResource,
            'waiver_evidence_ref' => $waiverEvidenceRef,
            'waiver_until' => $waiverUntil?->format(DATE_ATOM),
        ]));
        $sourceEventId = 'constraint_'.substr($sourceHash, 0, 48);

        try {
            return DB::transaction(function () use (
                $constraint,
                $fromStatus,
                $toStatus,
                $actorId,
                $occurredAt,
                $waiverUntil,
                $waiverEvidenceRef,
                $sourceHash,
                $sourceEventId,
                $linkedResource,
            ): WorkConstraintTransitionEvent {
                DB::select(
                    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                    ['lookahead-constraint-event:'.$constraint->organization_id.':'.$constraint->id],
                );
                $lockedConstraint = WorkConstraint::withTrashed()
                    ->where('organization_id', $constraint->organization_id)
                    ->whereKey($constraint->id)
                    ->lockForUpdate()
                    ->first();
                if ($lockedConstraint === null) {
                    throw new InvalidArgumentException('work_constraint_event_parent_unavailable');
                }
                $existing = WorkConstraintTransitionEvent::query()
                    ->where('organization_id', $constraint->organization_id)
                    ->where('source_event_id', $sourceEventId)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                $version = (int) WorkConstraintTransitionEvent::query()
                    ->where('organization_id', $constraint->organization_id)
                    ->where('constraint_id', $constraint->id)
                    ->max('event_version');

                return WorkConstraintTransitionEvent::query()->create([
                    'organization_id' => (int) $constraint->organization_id,
                    'project_id' => (int) $constraint->project_id,
                    'schedule_id' => (int) $constraint->schedule_id,
                    'task_id' => (int) $constraint->schedule_task_id,
                    'constraint_id' => (int) $constraint->id,
                    'event_version' => $version + 1,
                    'source_event_id' => $sourceEventId,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'constraint_type' => (string) $constraint->constraint_type,
                    'severity' => (string) $constraint->severity,
                    'waiver_until' => $waiverUntil,
                    'waiver_evidence_ref' => $waiverEvidenceRef,
                    'actor_id' => $actorId,
                    'occurred_at' => $occurredAt,
                    'source_hash' => $sourceHash,
                    'evidence_refs' => array_values(array_filter([
                        $waiverEvidenceRef === null ? null : [
                            'type' => 'waiver_evidence',
                            'id' => $waiverEvidenceRef,
                        ],
                        $linkedResource,
                    ])),
                ]);
            }, 5);
        } catch (QueryException $exception) {
            $existing = WorkConstraintTransitionEvent::query()
                ->where('organization_id', $constraint->organization_id)
                ->where('source_event_id', $sourceEventId)
                ->first();
            if ($existing === null || ! hash_equals((string) $existing->source_hash, $sourceHash)) {
                throw $exception;
            }

            return $existing;
        }
    }

    private function linkedResource(WorkConstraint $constraint): ?array
    {
        $metadata = (array) $constraint->metadata;
        $linked = $metadata['linked_action'] ?? $metadata['linked_entity'] ?? null;
        if (! is_array($linked)) {
            return null;
        }
        $type = $linked['type'] ?? null;
        $id = $linked['id'] ?? null;
        if (! is_string($type) || trim($type) === '' || ! is_numeric($id) || (int) $id < 1) {
            throw new InvalidArgumentException('work_constraint_linked_resource_invalid');
        }

        return [
            'type' => $type,
            'id' => (int) $id,
        ];
    }
}

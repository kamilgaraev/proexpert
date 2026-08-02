<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadEligibilityInput
{
    public function __construct(
        public int $taskId,
        public bool $container,
        public string $status,
        public DateTimeImmutable $plannedStart,
        public DateTimeImmutable $asOf,
        public array $constraints,
        public ?int $projectId = null,
        public ?int $scheduleId = null,
        public ?string $wbsCode = null,
        public ?int $ownerId = null,
        public ?int $contractorId = null,
        public ?int $zoneId = null,
        public string $taskType = 'task',
        public ?int $taskStateVersion = null,
        public ?string $taskStateSourceHash = null,
        public ?DateTimeImmutable $taskStateEffectiveAt = null,
    ) {
        $hasTaskStateIdentity = $taskStateVersion !== null
            || $taskStateSourceHash !== null
            || $taskStateEffectiveAt !== null;
        if ($taskId < 1
            || trim($status) === ''
            || trim($taskType) === ''
            || ! array_is_list($constraints)
            || ($hasTaskStateIdentity && (
                $taskStateVersion === null
                || $taskStateVersion < 1
                || $taskStateEffectiveAt === null
                || preg_match('/^[a-f0-9]{64}$/D', (string) $taskStateSourceHash) !== 1
            ))
        ) {
            throw new InvalidArgumentException('lookahead_eligibility_input_invalid');
        }

        foreach ($constraints as $constraint) {
            if (! $constraint instanceof LookaheadConstraintState) {
                throw new InvalidArgumentException('lookahead_eligibility_input_invalid');
            }
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            'as_of' => $this->asOf->format(DATE_ATOM),
            'constraints' => array_map(
                static fn (LookaheadConstraintState $constraint): array => [
                    'constraint_id' => $constraint->constraintId,
                    'linked_resource_id' => $constraint->linkedResourceId,
                    'linked_resource_type' => $constraint->linkedResourceType,
                    'opened_at' => $constraint->openedAt?->format(DATE_ATOM),
                    'severity' => $constraint->severity,
                    'status' => $constraint->status,
                    'type' => $constraint->type,
                    'transition_lineage' => $constraint->lineage?->canonicalIdentity(),
                    'waiver_evidence_ref' => $constraint->waiverEvidenceRef,
                    'waiver_until' => $constraint->waiverUntil?->format(DATE_ATOM),
                ],
                $this->constraints,
            ),
            'container' => $this->container,
            'contractor_id' => $this->contractorId,
            'owner_id' => $this->ownerId,
            'planned_start' => $this->plannedStart->format(DATE_ATOM),
            'project_id' => $this->projectId,
            'schedule_id' => $this->scheduleId,
            'status' => $this->status,
            'task_id' => $this->taskId,
            'task_state_effective_at' => $this->taskStateEffectiveAt?->format(DATE_ATOM),
            'task_state_source_hash' => $this->taskStateSourceHash,
            'task_state_version' => $this->taskStateVersion,
            'task_type' => $this->taskType,
            'wbs_code' => $this->wbsCode,
            'zone_id' => $this->zoneId,
        ];
    }

    public function eligibilityExplanation(): array
    {
        return [
            'task_status' => $this->status,
            'task_type' => $this->taskType,
            'task_state_version' => $this->taskStateVersion,
            'task_state_source_hash' => $this->taskStateSourceHash,
            'task_state_effective_at' => $this->taskStateEffectiveAt?->format(DATE_ATOM),
        ];
    }
}

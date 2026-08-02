<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SafetyTransitionFact
{
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public ?string $fromStatus,
        public string $toStatus,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $dueAt,
        public ?DateTimeImmutable $resolvedAt,
        public ?DateTimeImmutable $verifiedAt,
        public ?string $evidenceId,
    ) {
        if (! in_array($subjectType, ['incident', 'violation', 'corrective_action'], true)
            || $subjectId < 1
            || trim($toStatus) === ''
            || ($resolvedAt !== null && $resolvedAt > $occurredAt)
            || ($verifiedAt !== null && ($resolvedAt === null || $verifiedAt < $resolvedAt || $verifiedAt > $occurredAt))) {
            throw new InvalidArgumentException('safety_transition_fact_invalid');
        }
    }
}

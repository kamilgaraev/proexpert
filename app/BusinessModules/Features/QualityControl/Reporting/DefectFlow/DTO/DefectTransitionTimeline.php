<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DefectTransitionTimeline
{
    public function __construct(
        public int $defectId,
        public ?string $fromStatus,
        public string $toStatus,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $cohortAt,
        public ?DateTimeImmutable $resolvedAt,
        public bool $closureEvidencePresent,
    ) {
        if ($defectId < 1 || trim($toStatus) === '' || $occurredAt < $cohortAt || ($resolvedAt !== null && $resolvedAt < $cohortAt)) {
            throw new InvalidArgumentException('defect_transition_timeline_invalid');
        }
    }
}

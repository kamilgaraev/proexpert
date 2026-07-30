<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO;

use App\Support\Reporting\CanonicalLineageSummary;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadConstraintState
{
    public function __construct(
        public int $constraintId,
        public string $type,
        public string $severity,
        public string $status,
        public ?DateTimeImmutable $waiverUntil,
        public ?string $waiverEvidenceRef,
        public ?DateTimeImmutable $openedAt = null,
        public ?string $linkedResourceType = null,
        public ?int $linkedResourceId = null,
        public ?CanonicalLineageSummary $lineage = null,
    ) {
        if ($constraintId < 1
            || trim($type) === ''
            || trim($severity) === ''
            || trim($status) === ''
            || (($linkedResourceType === null) !== ($linkedResourceId === null))
            || ($linkedResourceId !== null && $linkedResourceId < 1)
        ) {
            throw new InvalidArgumentException('lookahead_constraint_state_invalid');
        }
    }
}

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
    ) {
        if ($taskId < 1 || trim($status) === '' || !array_is_list($constraints)) {
            throw new InvalidArgumentException('lookahead_eligibility_input_invalid');
        }

        foreach ($constraints as $constraint) {
            if (!$constraint instanceof LookaheadConstraintState) {
                throw new InvalidArgumentException('lookahead_eligibility_input_invalid');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\DTO;

use DateTimeImmutable;

final readonly class ProjectLaborEntryFact
{
    public function __construct(
        public int $timeEntryId,
        public int $organizationId,
        public int $employeeId,
        public int $projectId,
        public ?int $taskId,
        public ?int $workTypeId,
        public ?int $acceptedWorkId,
        public DateTimeImmutable $workDate,
        public string $status,
        public string $hours,
        public bool $billable,
        public ?string $acceptedUnits,
        public ?string $acceptedUnit,
    ) {
    }
}

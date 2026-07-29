<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProjectControlSourceIdentity
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $scheduleId,
        public int $baselineVersion,
        public DateTimeImmutable $statusDate,
        public string $wipVersion,
        public string $progressWatermark,
        public string $actualCostWatermark,
        public string $sourceHash,
    ) {
        if ($organizationId < 1
            || $projectId < 1
            || $scheduleId < 1
            || $baselineVersion < 1
            || trim($wipVersion) === ''
            || trim($progressWatermark) === ''
            || trim($actualCostWatermark) === ''
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
        ) {
            throw new InvalidArgumentException('project_control_source_identity_invalid');
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            $this->organizationId,
            $this->projectId,
            $this->scheduleId,
            $this->baselineVersion,
            $this->statusDate->format('Y-m-d'),
            $this->wipVersion,
            $this->progressWatermark,
            $this->actualCostWatermark,
            $this->sourceHash,
        ];
    }
}

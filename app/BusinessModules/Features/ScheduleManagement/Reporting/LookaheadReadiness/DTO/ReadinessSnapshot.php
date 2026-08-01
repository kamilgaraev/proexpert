<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessState;

final readonly class ReadinessSnapshot
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $scheduleId,
        public int $commitmentRevisionId,
        public int $commitmentTaskId,
        public int $snapshotRevision,
        public ReadinessState $state,
        public array $componentOutcomes,
        public array $reasonCodes,
        public string $policyHash,
        public string $scheduleRevisionHash,
        public string $commitmentRevisionHash,
        public string $sourceWatermark,
        public string $calculatedAtUtc,
        public array $blockerEventIds,
        public array $waiverEventIds,
        public ?array $actualComparison,
        public string $readinessHash,
        public string $snapshotHash,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\DTO;

final readonly class AssignmentData
{
    public function __construct(
        public int $organizationAssetId,
        public int $projectId,
        public string $plannedStartAt,
        public ?string $plannedEndAt = null,
        public ?int $assetRequestId = null,
        public ?int $scheduleTaskId = null,
        public ?float $plannedHours = null,
        public ?string $comment = null,
        public ?int $replacesAssignmentId = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

final readonly class WorkforceCapacityDeferredCaptureClaim
{
    public function __construct(
        public int $requestId,
        public string $claimToken,
        public WorkforceCapacityFrozenCapturePins $pins,
        public ?string $cohortCursor,
        public ?string $hashCursor,
        public int $snapshotCount,
        public int $chunkCount,
        public int $attemptCount,
    ) {}
}

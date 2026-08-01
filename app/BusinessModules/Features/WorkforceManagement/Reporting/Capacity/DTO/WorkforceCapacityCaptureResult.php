<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

final readonly class WorkforceCapacityCaptureResult
{
    public function __construct(
        public int $snapshotCount,
        public int $chunkCount,
        public ?string $cursor,
    ) {}
}

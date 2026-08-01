<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

interface WorkforceCapacitySnapshotStore
{
    public function appendBatch(
        string $mutationId,
        ?string $priorCursor,
        string $cursor,
        array $snapshots,
    ): void;

    public function completeCapture(
        string $mutationId,
        int $organizationId,
        ?string $cursor,
        int $snapshotCount,
        int $chunkCount,
    ): void;
}

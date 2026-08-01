<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityDeferredCaptureClaim;
use DateTimeImmutable;

interface WorkforceCapacityDeferredCaptureStore
{
    public function claim(int $captureRequestId, DateTimeImmutable $at, int $leaseSeconds): ?WorkforceCapacityDeferredCaptureClaim;

    public function appendClaimedChunk(
        WorkforceCapacityDeferredCaptureClaim $claim,
        string $cohortCursor,
        string $hashCursor,
        array $snapshots,
        bool $completed,
        DateTimeImmutable $at,
    ): bool;

    public function failClaim(
        WorkforceCapacityDeferredCaptureClaim $claim,
        string $safeErrorCode,
        DateTimeImmutable $retryAt,
        bool $deadLettered,
    ): bool;

    public function recoverableIds(DateTimeImmutable $at, int $limit, int $leaseSeconds): array;
}

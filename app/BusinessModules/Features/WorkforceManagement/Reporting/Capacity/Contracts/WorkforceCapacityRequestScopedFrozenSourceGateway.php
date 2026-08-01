<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCaptureRequestState;

interface WorkforceCapacityRequestScopedFrozenSourceGateway
{
    public function isInsideOwnerTransaction(): bool;

    public function createRequest(WorkforceCapacityFrozenCapturePins $pins): WorkforceCapacityFrozenCaptureRequestState;

    public function materializeRanges(WorkforceCapacityFrozenCapturePins $pins, int $captureRequestId): int;

    public function materializeSourceRows(int $captureRequestId): int;

    public function stageLifecycleRanges(
        int $captureRequestId,
        int $organizationId,
        int $employeeId,
        string $dismissalDate,
    ): int;

    public function sealRequest(int $captureRequestId, int $rangeCount, int $sourceRowCount): bool;

    public function nextKeys(int $captureRequestId, ?string $afterSortIdentity, int $limit): array;

    public function sourceProjections(int $captureRequestId, array $keys): iterable;
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

interface WorkforceCapacityDeferredSourceReader
{
    public function nextKeys(int $captureRequestId, ?string $afterSortIdentity, int $limit): array;

    public function readBatch(int $captureRequestId, array $keys): array;
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use DateTimeImmutable;

interface ProcurementCycleSourceState
{
    public function activePolicy(
        int $organizationId,
        ?int $projectId,
        DateTimeImmutable $occurredAt,
    ): ?ProcurementCyclePolicyVersion;

    public function requestCreatedSnapshot(
        int $organizationId,
        int $purchaseRequestLineId,
    ): ?ProcurementProcessDimensionSnapshot;

    public function policyAllows(
        ProcurementProcessDimensionSnapshot $snapshot,
        ProcurementTerminalReason $reason,
    ): bool;

    public function eventExists(
        int $organizationId,
        int $purchaseRequestLineId,
        ProcurementProcessEventCode $eventCode,
    ): bool;

    public function isFullyReceived(int $purchaseOrderId, int $purchaseRequestLineId): bool;
}

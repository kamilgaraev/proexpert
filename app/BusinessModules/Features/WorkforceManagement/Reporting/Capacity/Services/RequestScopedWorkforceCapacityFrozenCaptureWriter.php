<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityFrozenCaptureWriter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityRequestScopedFrozenSourceGateway;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCaptureReceipt;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use DateTimeImmutable;
use LogicException;

final readonly class RequestScopedWorkforceCapacityFrozenCaptureWriter implements WorkforceCapacityFrozenCaptureWriter
{
    public function __construct(
        private WorkforceCapacityRequestScopedFrozenSourceGateway $gateway,
    ) {}

    public function freezeAndEnqueue(
        WorkforceCapacityCaptureCommand $command,
        WorkforceCapacityPolicyDefinition $policy,
        DateTimeImmutable $capturedAt,
        string $businessDate,
    ): WorkforceCapacityFrozenCaptureReceipt {
        if (! $this->gateway->isInsideOwnerTransaction()) {
            throw new LogicException('workforce_capacity_owner_transaction_required');
        }

        $pins = new WorkforceCapacityFrozenCapturePins($command, $policy, $capturedAt, $businessDate);
        $request = $this->gateway->createRequest($pins);
        if (! $request->preparationRequired) {
            return new WorkforceCapacityFrozenCaptureReceipt($request->requestId, $request->dispatchRequired);
        }
        $captureRequestId = $request->requestId;
        $rangeCount = $this->gateway->materializeRanges($pins, $captureRequestId);
        $sourceRowCount = $rangeCount === 0 ? 0 : $this->gateway->materializeSourceRows($captureRequestId);
        $dispatchRequired = $this->gateway->sealRequest($captureRequestId, $rangeCount, $sourceRowCount);

        return new WorkforceCapacityFrozenCaptureReceipt($captureRequestId, $dispatchRequired);
    }
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityClock;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityLifecycleCaptureCoordinator;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityPolicySource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityRequestScopedFrozenSourceGateway;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityLifecycleCaptureDraft;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class RequestScopedWorkforceCapacityLifecycleCaptureCoordinator implements WorkforceCapacityLifecycleCaptureCoordinator
{
    public function __construct(
        private WorkforceCapacityPolicySource $policies,
        private WorkforceCapacityClock $clock,
        private WorkforceCapacityRequestScopedFrozenSourceGateway $gateway,
        private WorkforceCapacityDeferredCaptureDispatcher $dispatcher,
    ) {}

    public function beginDismissal(
        int $organizationId,
        int $employeeId,
        string $dismissalDate,
    ): WorkforceCapacityLifecycleCaptureDraft {
        if (! $this->gateway->isInsideOwnerTransaction()) {
            throw new LogicException('workforce_capacity_owner_transaction_required');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dismissalDate);
        if ($organizationId < 1
            || $employeeId < 1
            || $date === false
            || $date->format('Y-m-d') !== $dismissalDate) {
            throw new InvalidArgumentException('workforce_capacity_lifecycle_identity_invalid');
        }

        $capturedAt = $this->clock->now();
        $policy = $this->policies->forOrganization($organizationId);
        $businessDate = $capturedAt
            ->setTimezone(new \DateTimeZone($policy->timezone))
            ->format('Y-m-d');
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: sprintf(
                'employee_lifecycle:%d:%s',
                $employeeId,
                hash('sha256', $organizationId.':'.$employeeId.':'.$dismissalDate),
            ),
            organizationId: $organizationId,
            sourceType: 'employee_lifecycle',
            oldState: [
                'employee_id' => $employeeId,
                'employment_status' => 'active',
                'dismissal_date' => null,
            ],
            newState: [
                'employee_id' => $employeeId,
                'employment_status' => 'dismissed',
                'dismissal_date' => $dismissalDate,
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );
        $request = $this->gateway->createRequest(new WorkforceCapacityFrozenCapturePins(
            $command,
            $policy,
            $capturedAt,
            $businessDate,
        ));
        if (! $request->preparationRequired) {
            return new WorkforceCapacityLifecycleCaptureDraft(
                $request->requestId,
                $organizationId,
                $employeeId,
                $dismissalDate,
                0,
                preparationRequired: false,
                dispatchRequired: $request->dispatchRequired,
            );
        }
        $stagedRangeCount = $this->gateway->stageLifecycleRanges(
            $request->requestId,
            $organizationId,
            $employeeId,
            $dismissalDate,
        );

        return new WorkforceCapacityLifecycleCaptureDraft(
            $request->requestId,
            $organizationId,
            $employeeId,
            $dismissalDate,
            $stagedRangeCount,
        );
    }

    public function finishDismissal(WorkforceCapacityLifecycleCaptureDraft $draft): void
    {
        if (! $this->gateway->isInsideOwnerTransaction()) {
            throw new LogicException('workforce_capacity_owner_transaction_required');
        }
        if (! $draft->preparationRequired) {
            if ($draft->dispatchRequired) {
                $this->dispatcher->dispatchAfterCommit($draft->requestId);
            }

            return;
        }
        $postMutationRanges = $this->gateway->stageLifecycleRanges(
            $draft->requestId,
            $draft->organizationId,
            $draft->employeeId,
            $draft->dismissalDate,
        );
        $rangeCount = $draft->stagedRangeCount + $postMutationRanges;
        $sourceRowCount = $rangeCount === 0 ? 0 : $this->gateway->materializeSourceRows($draft->requestId);
        if ($this->gateway->sealRequest($draft->requestId, $rangeCount, $sourceRowCount)) {
            $this->dispatcher->dispatchAfterCommit($draft->requestId);
        }
    }
}

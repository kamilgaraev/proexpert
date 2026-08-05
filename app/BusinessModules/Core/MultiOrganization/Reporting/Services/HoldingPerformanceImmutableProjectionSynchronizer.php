<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentTransactionEventVersion;
use DateTimeInterface;

final readonly class HoldingPerformanceImmutableProjectionSynchronizer
{
    public function __construct(
        private HoldingPerformanceImmutableEventSource $events,
        private AcceptedWorkHoldingFactProducer $acceptedWork,
        private HoldingPaymentEventFactProducer $payments,
    ) {}

    public function synchronize(
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        DateTimeInterface $asOf,
        DateTimeInterface $recordedCutoff,
    ): void {
        foreach ($this->events->acceptedWorkVersions(
            $organizationIds,
            $projectIds,
            $coverageStartedAt,
            $asOf,
            $recordedCutoff,
        ) as $event) {
            if ($event instanceof HoldingAcceptedWorkEventVersion && $event->active) {
                $this->acceptedWork->projectEvent($event);
            }
        }
        foreach ($this->events->paymentVersions(
            $organizationIds,
            $projectIds,
            $coverageStartedAt,
            $asOf,
            $recordedCutoff,
        ) as $event) {
            if ($event instanceof HoldingPaymentTransactionEventVersion && $event->active) {
                $this->payments->project($event);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts\PayrollReadinessEvidenceSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts\PayrollReadinessSnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPeriodIdentity;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessSnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use DateTimeImmutable;

final readonly class PayrollReadinessOwnerSnapshotRecorder
{
    public function __construct(
        private PayrollReadinessSnapshotBuilder $builder,
        private PayrollReadinessEvidenceSource $evidenceSource,
        private PayrollReadinessSnapshotStore $store,
    ) {}

    public function recordBlocked(
        PayrollReadinessPeriodIdentity $period,
        int $actorUserId,
        DateTimeImmutable $evaluatedAt,
        string $ownerSourceHash,
        PayrollReadinessReason $reason,
        array $gapCodes = [],
    ): PayrollReadinessSnapshot {
        $snapshot = $this->builder->blocked(
            organizationId: $period->organizationId,
            periodId: $period->periodId,
            projectId: $period->projectId,
            periodStart: $period->periodStart,
            periodEnd: $period->periodEnd,
            actorUserId: $actorUserId,
            evaluatedAt: $evaluatedAt,
            ownerSourceHash: $ownerSourceHash,
            reason: $reason,
            sourceRows: fn (): iterable => $this->evidenceSource->sourceRows($period),
            validationIssues: fn (): iterable => $this->evidenceSource->validationIssues($period),
            gapCodes: $gapCodes,
        );
        $this->store->append($snapshot, $snapshot->items());

        return $snapshot;
    }

    public function recordLocked(
        PayrollReadinessPeriodIdentity $period,
        int $actorUserId,
        DateTimeImmutable $evaluatedAt,
        string $lockedSourceHash,
    ): PayrollReadinessSnapshot {
        $snapshot = $this->builder->locked(
            organizationId: $period->organizationId,
            periodId: $period->periodId,
            projectId: $period->projectId,
            periodStart: $period->periodStart,
            periodEnd: $period->periodEnd,
            actorUserId: $actorUserId,
            evaluatedAt: $evaluatedAt,
            lockedSourceHash: $lockedSourceHash,
            sourceRows: fn (): iterable => $this->evidenceSource->sourceRows($period),
        );
        $this->store->append($snapshot, $snapshot->items());

        return $snapshot;
    }
}

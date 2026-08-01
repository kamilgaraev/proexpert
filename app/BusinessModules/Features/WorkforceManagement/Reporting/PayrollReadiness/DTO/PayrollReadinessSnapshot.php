<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessSnapshotKind;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PayrollReadinessSnapshot
{
    public function __construct(
        public int $organizationId,
        public int $periodId,
        public ?int $projectId,
        public string $periodStart,
        public string $periodEnd,
        public PayrollReadinessSnapshotKind $kind,
        public PayrollReadinessReason $reason,
        public int $actorUserId,
        public DateTimeImmutable $evaluatedAt,
        public string $schemaVersion,
        public string $formulaVersion,
        public PayrollReadinessPolicyDefinition $policy,
        public string $ownerSourceHash,
        public ?string $lockedSourceHash,
        public array $blockerCodes,
        public array $gapCodes,
        public int $sourceRowCount,
        public int $validationIssueCount,
        public int $blockerCount,
        public int $itemCount,
        public string $itemsHash,
        public string $stateHash,
        public string $sourceHash,
        private Closure $itemStream,
    ) {
        if ($this->organizationId < 1 || $this->periodId < 1 || $this->actorUserId < 1) {
            throw new InvalidArgumentException('payroll_readiness_identity_invalid');
        }

        if ($this->evaluatedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('payroll_readiness_evaluated_at_must_be_utc');
        }

        foreach ([$this->ownerSourceHash, $this->itemsHash, $this->stateHash, $this->sourceHash] as $hash) {
            if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                throw new InvalidArgumentException('payroll_readiness_source_hash_invalid');
            }
        }

        if ($this->lockedSourceHash !== null && ! preg_match('/^[a-f0-9]{64}$/', $this->lockedSourceHash)) {
            throw new InvalidArgumentException('payroll_readiness_locked_source_hash_invalid');
        }

        if ($this->itemCount < count($this->policy->checkOrder)
            || $this->sourceRowCount < 0
            || $this->validationIssueCount < 0
            || $this->blockerCount < 0
            || $this->blockerCount > $this->validationIssueCount
            || $this->itemCount !== count($this->policy->checkOrder)
                + $this->sourceRowCount
                + $this->validationIssueCount) {
            throw new InvalidArgumentException('payroll_readiness_evidence_counts_invalid');
        }

        $this->policy->assertEvidenceState(
            $this->reason,
            $this->sourceRowCount,
            $this->blockerCount,
            $this->blockerCodes,
        );

        if ($this->kind === PayrollReadinessSnapshotKind::LOCK_SUCCEEDED) {
            if ($this->reason !== PayrollReadinessReason::LOCKED
                || $this->lockedSourceHash !== $this->ownerSourceHash
                || $this->blockerCodes !== []
                || $this->gapCodes !== []
                || $this->sourceRowCount < 1
                || $this->validationIssueCount !== 0
                || $this->blockerCount !== 0) {
                throw new InvalidArgumentException('payroll_readiness_locked_snapshot_invalid');
            }
        } elseif ($this->reason === PayrollReadinessReason::LOCKED || $this->lockedSourceHash !== null) {
            throw new InvalidArgumentException('payroll_readiness_blocked_snapshot_invalid');
        }
    }

    public function toPersistence(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'payroll_period_id' => $this->periodId,
            'project_id' => $this->projectId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'snapshot_kind' => $this->kind->value,
            'result_code' => $this->reason === PayrollReadinessReason::LOCKED ? 'locked' : 'blocked',
            'reason_code' => $this->reason->value,
            'actor_user_id' => $this->actorUserId,
            'evaluated_at' => $this->evaluatedAt->format(DATE_ATOM),
            'schema_version' => $this->schemaVersion,
            'formula_version' => $this->formulaVersion,
            'policy_version' => $this->policy->version,
            'policy_hash' => $this->policy->hash(),
            'policy_definition' => $this->policy->canonical(),
            'owner_source_hash' => $this->ownerSourceHash,
            'locked_source_hash' => $this->lockedSourceHash,
            'source_hash' => $this->sourceHash,
            'state_hash' => $this->stateHash,
            'items_hash' => $this->itemsHash,
            'blocker_codes' => $this->blockerCodes,
            'gap_codes' => $this->gapCodes,
            'source_row_count' => $this->sourceRowCount,
            'validation_issue_count' => $this->validationIssueCount,
            'blocker_count' => $this->blockerCount,
            'item_count' => $this->itemCount,
        ];
    }

    public function items(): iterable
    {
        return ($this->itemStream)();
    }
}

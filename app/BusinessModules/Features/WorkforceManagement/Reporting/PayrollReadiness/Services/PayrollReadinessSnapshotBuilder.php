<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessEvidenceItem;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessSnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessSnapshotKind;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Traversable;

final readonly class PayrollReadinessSnapshotBuilder
{
    private const SCHEMA_VERSION = 'payroll-readiness-source.v1';

    private const FORMULA_VERSION = 'payroll-readiness-checks.v1';

    private const EMPTY_ITEMS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private PayrollReadinessPolicyDefinition $policy) {}

    public function blocked(
        int $organizationId,
        int $periodId,
        ?int $projectId,
        string $periodStart,
        string $periodEnd,
        int $actorUserId,
        DateTimeImmutable $evaluatedAt,
        string $ownerSourceHash,
        PayrollReadinessReason $reason,
        iterable|callable $sourceRows,
        iterable|callable $validationIssues,
        array $gapCodes = [],
    ): PayrollReadinessSnapshot {
        if ($reason === PayrollReadinessReason::LOCKED || ! $this->policy->allows($reason)) {
            throw new InvalidArgumentException('payroll_readiness_blocked_reason_invalid');
        }

        return $this->build(
            organizationId: $organizationId,
            periodId: $periodId,
            projectId: $projectId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            kind: PayrollReadinessSnapshotKind::PRE_LOCK_BLOCKED,
            reason: $reason,
            actorUserId: $actorUserId,
            evaluatedAt: $evaluatedAt,
            ownerSourceHash: $ownerSourceHash,
            lockedSourceHash: null,
            sourceRows: $this->repeatable($sourceRows),
            validationIssues: $this->repeatable($validationIssues),
            gapCodes: $gapCodes,
        );
    }

    public function locked(
        int $organizationId,
        int $periodId,
        ?int $projectId,
        string $periodStart,
        string $periodEnd,
        int $actorUserId,
        DateTimeImmutable $evaluatedAt,
        string $lockedSourceHash,
        iterable|callable $sourceRows,
    ): PayrollReadinessSnapshot {
        return $this->build(
            organizationId: $organizationId,
            periodId: $periodId,
            projectId: $projectId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            kind: PayrollReadinessSnapshotKind::LOCK_SUCCEEDED,
            reason: PayrollReadinessReason::LOCKED,
            actorUserId: $actorUserId,
            evaluatedAt: $evaluatedAt,
            ownerSourceHash: $lockedSourceHash,
            lockedSourceHash: $lockedSourceHash,
            sourceRows: $this->repeatable($sourceRows),
            validationIssues: static fn (): array => [],
            gapCodes: [],
        );
    }

    private function build(
        int $organizationId,
        int $periodId,
        ?int $projectId,
        string $periodStart,
        string $periodEnd,
        PayrollReadinessSnapshotKind $kind,
        PayrollReadinessReason $reason,
        int $actorUserId,
        DateTimeImmutable $evaluatedAt,
        string $ownerSourceHash,
        ?string $lockedSourceHash,
        Closure $sourceRows,
        Closure $validationIssues,
        array $gapCodes,
    ): PayrollReadinessSnapshot {
        sort($gapCodes, SORT_STRING);
        $gapCodes = array_values(array_unique($gapCodes));
        $itemStream = fn (): iterable => $this->itemStream(
            $reason,
            $organizationId,
            $periodId,
            $projectId,
            $sourceRows,
            $validationIssues,
        );
        $summary = $this->summarize($itemStream);
        $blockerCodes = $summary['blocker_codes'];

        $stateHash = $this->hash([
            'organization_id' => $organizationId,
            'payroll_period_id' => $periodId,
            'project_id' => $projectId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'snapshot_kind' => $kind->value,
            'reason_code' => $reason->value,
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'policy_hash' => $this->policy->hash(),
            'owner_source_hash' => $ownerSourceHash,
            'locked_source_hash' => $lockedSourceHash,
            'blocker_codes' => $blockerCodes,
            'gap_codes' => $gapCodes,
            'source_row_count' => $summary['source_row_count'],
            'validation_issue_count' => $summary['validation_issue_count'],
            'blocker_count' => $summary['blocker_count'],
            'item_count' => $summary['item_count'],
            'items_hash' => $summary['items_hash'],
        ]);
        $sourceHash = $this->hash([
            'state_hash' => $stateHash,
            'actor_user_id' => $actorUserId,
            'evaluated_at' => $evaluatedAt->format('Y-m-d\TH:i:s.uP'),
        ]);

        return new PayrollReadinessSnapshot(
            organizationId: $organizationId,
            periodId: $periodId,
            projectId: $projectId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            kind: $kind,
            reason: $reason,
            actorUserId: $actorUserId,
            evaluatedAt: $evaluatedAt,
            schemaVersion: self::SCHEMA_VERSION,
            formulaVersion: self::FORMULA_VERSION,
            policy: $this->policy,
            ownerSourceHash: $ownerSourceHash,
            lockedSourceHash: $lockedSourceHash,
            blockerCodes: $blockerCodes,
            gapCodes: $gapCodes,
            sourceRowCount: $summary['source_row_count'],
            validationIssueCount: $summary['validation_issue_count'],
            blockerCount: $summary['blocker_count'],
            itemCount: $summary['item_count'],
            itemsHash: $summary['items_hash'],
            stateHash: $stateHash,
            sourceHash: $sourceHash,
            itemStream: $itemStream,
        );
    }

    private function summarize(Closure $itemStream): array
    {
        $itemCount = 0;
        $sourceRowCount = 0;
        $validationIssueCount = 0;
        $blockerCount = 0;
        $blockerCodes = [];
        $itemsHash = self::EMPTY_ITEMS_HASH;

        foreach ($itemStream() as $item) {
            if (! $item instanceof PayrollReadinessEvidenceItem) {
                throw new InvalidArgumentException('payroll_readiness_item_invalid');
            }

            $itemCount++;
            $itemsHash = $this->nextItemsHash($itemsHash, $itemCount, $item->contentHash);
            if ($item->sourceType === 'payroll_source_row') {
                $sourceRowCount++;
            }
            if ($item->sourceType === 'validation_issue') {
                $validationIssueCount++;
                if (in_array($item->status, $this->policy->blockingSeverities, true)) {
                    $blockerCount++;
                    $blockerCodes[$item->code] = true;
                }
            }
        }

        $codes = array_keys($blockerCodes);
        sort($codes, SORT_STRING);

        return [
            'item_count' => $itemCount,
            'source_row_count' => $sourceRowCount,
            'validation_issue_count' => $validationIssueCount,
            'blocker_count' => $blockerCount,
            'blocker_codes' => $codes,
            'items_hash' => $itemsHash,
        ];
    }

    private function itemStream(
        PayrollReadinessReason $reason,
        int $organizationId,
        int $periodId,
        ?int $projectId,
        Closure $sourceRows,
        Closure $validationIssues,
    ): iterable {
        yield from $this->checkItems($reason);

        $lastSourceId = 0;
        foreach ($sourceRows() as $row) {
            $row = (array) $row;
            $canonical = $this->canonicalSourceRow($row, $organizationId, $periodId, $projectId);
            if ($canonical['id'] <= $lastSourceId) {
                throw new InvalidArgumentException('payroll_readiness_source_order_invalid');
            }
            $lastSourceId = $canonical['id'];

            yield new PayrollReadinessEvidenceItem(
                sourceType: 'payroll_source_row',
                sourceId: $canonical['id'],
                code: $canonical['source_type'],
                status: 'captured',
                contentHash: $this->hash($canonical),
                lineage: [
                    'payroll_period_id' => $canonical['payroll_period_id'],
                    'project_id' => $canonical['project_id'],
                    'work_order_id' => $canonical['work_order_id'],
                    'work_order_line_id' => $canonical['work_order_line_id'],
                    'timesheet_entry_id' => $canonical['timesheet_entry_id'],
                    'work_date' => $canonical['work_date'],
                ],
            );
        }

        $lastIssueId = 0;
        foreach ($validationIssues() as $issue) {
            $issue = (array) $issue;
            if (($issue['resolved_at'] ?? null) !== null) {
                continue;
            }

            $canonical = $this->canonicalValidationIssue($issue, $organizationId, $periodId, $projectId);
            if ($canonical['id'] <= $lastIssueId) {
                throw new InvalidArgumentException('payroll_readiness_validation_order_invalid');
            }
            $lastIssueId = $canonical['id'];

            yield new PayrollReadinessEvidenceItem(
                sourceType: 'validation_issue',
                sourceId: $canonical['id'],
                code: $canonical['issue_code'],
                status: $canonical['severity'],
                contentHash: $this->hash($canonical),
                lineage: [
                    'payroll_period_id' => $canonical['payroll_period_id'],
                    'project_id' => $canonical['project_id'],
                    'entity_type' => $canonical['entity_type'],
                    'entity_id' => $canonical['entity_id'],
                ],
            );
        }
    }

    private function canonicalSourceRow(array $row, int $organizationId, int $periodId, ?int $projectId): array
    {
        if ((int) ($row['organization_id'] ?? 0) !== $organizationId
            || (int) ($row['payroll_period_id'] ?? 0) !== $periodId
            || ($projectId !== null && (int) ($row['project_id'] ?? 0) !== $projectId)) {
            throw new InvalidArgumentException('payroll_readiness_source_lineage_mismatch');
        }

        return [
            'id' => $this->positiveInt($row['id'] ?? null, 'payroll_readiness_source_identity_invalid'),
            'organization_id' => $organizationId,
            'payroll_period_id' => $periodId,
            'employee_id' => $this->positiveInt($row['employee_id'] ?? null, 'payroll_readiness_source_identity_invalid'),
            'project_id' => $this->nullableInt($row['project_id'] ?? null),
            'work_order_id' => $this->nullableInt($row['work_order_id'] ?? null),
            'work_order_line_id' => $this->nullableInt($row['work_order_line_id'] ?? null),
            'timesheet_entry_id' => $this->nullableInt($row['timesheet_entry_id'] ?? null),
            'work_date' => (string) ($row['work_date'] ?? ''),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'hours' => $this->decimal($row['hours'] ?? null, 4),
            'amount' => $this->decimal($row['amount'] ?? null, 2),
            'payload' => $this->canonicalValue($this->normalizedPayload($row['payload'] ?? null)),
        ];
    }

    private function canonicalValidationIssue(
        array $issue,
        int $organizationId,
        int $periodId,
        ?int $projectId,
    ): array {
        if ((int) ($issue['organization_id'] ?? 0) !== $organizationId
            || (int) ($issue['payroll_period_id'] ?? 0) !== $periodId
            || ($projectId !== null
                && ($issue['project_id'] ?? null) !== null
                && (int) $issue['project_id'] !== $projectId)) {
            throw new InvalidArgumentException('payroll_readiness_validation_lineage_mismatch');
        }

        return [
            'id' => $this->positiveInt($issue['id'] ?? null, 'payroll_readiness_validation_identity_invalid'),
            'organization_id' => $organizationId,
            'payroll_period_id' => $periodId,
            'severity' => (string) ($issue['severity'] ?? ''),
            'issue_code' => (string) ($issue['issue_code'] ?? ''),
            'entity_type' => (string) ($issue['entity_type'] ?? ''),
            'entity_id' => $this->nullableInt($issue['entity_id'] ?? null),
            'employee_id' => $this->nullableInt($issue['employee_id'] ?? null),
            'project_id' => $this->nullableInt($issue['project_id'] ?? null),
            'payload' => $this->canonicalValue($this->normalizedPayload($issue['payload'] ?? null)),
            'resolved_at' => null,
        ];
    }

    private function checkItems(PayrollReadinessReason $reason): iterable
    {
        $blockedAt = match ($reason) {
            PayrollReadinessReason::PERIOD_NOT_VALIDATED => 0,
            PayrollReadinessReason::SOURCE_EMPTY => 1,
            PayrollReadinessReason::SOURCE_CHANGED => 2,
            PayrollReadinessReason::VALIDATION_BLOCKERS => 3,
            PayrollReadinessReason::ACCOUNTING_BLOCKERS => 4,
            PayrollReadinessReason::LOCKED => null,
        };

        foreach ($this->policy->checkOrder as $position => $check) {
            $status = $blockedAt === null || $position < $blockedAt
                ? 'passed'
                : ($position === $blockedAt ? 'blocked' : 'not_evaluated');
            yield new PayrollReadinessEvidenceItem(
                sourceType: 'readiness_check',
                sourceId: null,
                code: $check,
                status: $status,
                contentHash: $this->hash(['check' => $check, 'status' => $status]),
                lineage: [],
            );
        }
    }

    private function repeatable(iterable|callable $source): Closure
    {
        if (is_callable($source)) {
            return static function () use ($source): iterable {
                $rows = $source();
                if (! is_iterable($rows)) {
                    throw new InvalidArgumentException('payroll_readiness_evidence_source_invalid');
                }

                return $rows;
            };
        }

        if (is_array($source)) {
            usort($source, static fn (mixed $left, mixed $right): int => (int) ((array) $left)['id'] <=> (int) ((array) $right)['id']);

            return static fn (): array => $source;
        }

        if ($source instanceof Traversable) {
            throw new InvalidArgumentException('payroll_readiness_evidence_source_must_be_repeatable');
        }

        throw new InvalidArgumentException('payroll_readiness_evidence_source_invalid');
    }

    private function decimal(mixed $value, int $scale): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('payroll_readiness_decimal_invalid');
        }

        $text = (string) $value;
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $matches) !== 1) {
            throw new InvalidArgumentException('payroll_readiness_decimal_invalid');
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException('payroll_readiness_decimal_scale_invalid');
        }
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $sign = $matches[1] === '-' && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '';

        return $sign.$integer.'.'.$fraction;
    }

    private function normalizedPayload(mixed $payload): mixed
    {
        if (! is_string($payload)) {
            return $payload;
        }

        $decoded = json_decode($payload, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $payload;
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalValue($nested);
        }

        return $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function positiveInt(mixed $value, string $error): int
    {
        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException($error);
        }

        return $integer;
    }

    private function nextItemsHash(string $previousHash, int $position, string $contentHash): string
    {
        return hash('sha256', $previousHash.':'.$position.':'.$contentHash);
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}

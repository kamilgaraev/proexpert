<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts\PayrollReadinessSnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessSnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\PayrollReadinessSnapshotItemRecord;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\PayrollReadinessSnapshotRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentPayrollReadinessSnapshotStore implements PayrollReadinessSnapshotStore
{
    public function append(PayrollReadinessSnapshot $snapshot, iterable $items): void
    {
        DB::transaction(function () use ($snapshot, $items): void {
            $existing = PayrollReadinessSnapshotRecord::query()
                ->where('organization_id', $snapshot->organizationId)
                ->where('payroll_period_id', $snapshot->periodId)
                ->where('source_hash', $snapshot->sourceHash)
                ->where('snapshot_kind', $snapshot->kind->value)
                ->first();

            if ($existing instanceof PayrollReadinessSnapshotRecord) {
                return;
            }

            $payload = $snapshot->toPersistence();
            $capturedAt = $this->now();

            if ($capturedAt < $snapshot->evaluatedAt) {
                throw new LogicException('payroll_readiness_evaluated_at_is_in_future');
            }

            $payload['evaluated_at'] = $snapshot->evaluatedAt;
            $payload['created_at'] = $capturedAt;

            $record = PayrollReadinessSnapshotRecord::query()->create($payload);

            $batch = [];
            $position = 0;
            $itemsHash = str_repeat('0', 64);
            $sourceRowCount = 0;
            $validationIssueCount = 0;
            $blockerCount = 0;
            foreach ($items as $item) {
                $position++;
                $itemsHash = hash('sha256', $itemsHash.':'.$position.':'.$item->contentHash);
                $sourceRowCount += $item->sourceType === 'payroll_source_row' ? 1 : 0;
                $validationIssueCount += $item->sourceType === 'validation_issue' ? 1 : 0;
                $blockerCount += $item->sourceType === 'validation_issue' && $item->status === 'blocking' ? 1 : 0;
                $batch[] = [
                    ...$item->toPersistence($position),
                    'organization_id' => $snapshot->organizationId,
                    'payroll_period_id' => $snapshot->periodId,
                    'payroll_readiness_snapshot_id' => $record->getKey(),
                ];

                if (count($batch) === 500) {
                    $capturedAt = $this->insertBatch($batch, $capturedAt);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $capturedAt = $this->insertBatch($batch, $capturedAt);
            }

            if ($position !== $snapshot->itemCount
                || $itemsHash !== $snapshot->itemsHash
                || $sourceRowCount !== $snapshot->sourceRowCount
                || $validationIssueCount !== $snapshot->validationIssueCount
                || $blockerCount !== $snapshot->blockerCount) {
                throw new LogicException('payroll_readiness_evidence_stream_changed');
            }

            $sealedAt = $this->now();

            if ($sealedAt < $capturedAt) {
                throw new LogicException('payroll_readiness_capture_clock_moved_backwards');
            }

            $sealed = DB::table((new PayrollReadinessSnapshotRecord)->getTable())
                ->where('id', $record->getKey())
                ->whereNull('sealed_at')
                ->update(['sealed_at' => $sealedAt]);

            if ($sealed !== 1) {
                throw new LogicException('payroll_readiness_snapshot_seal_failed');
            }
        });
    }

    private function insertBatch(array $batch, DateTimeImmutable $notBefore): DateTimeImmutable
    {
        $capturedAt = $this->now();

        if ($capturedAt < $notBefore) {
            throw new LogicException('payroll_readiness_capture_clock_moved_backwards');
        }

        DB::table((new PayrollReadinessSnapshotItemRecord)->getTable())->insert(array_map(
            static fn (array $item): array => [...$item, 'created_at' => $capturedAt],
            $batch,
        ));

        return $capturedAt;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

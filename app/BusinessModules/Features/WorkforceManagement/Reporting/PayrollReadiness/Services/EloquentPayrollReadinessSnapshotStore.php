<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts\PayrollReadinessSnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessSnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\PayrollReadinessSnapshotItemRecord;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\PayrollReadinessSnapshotRecord;
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
            $payload['evaluated_at'] = $snapshot->evaluatedAt;
            $payload['created_at'] = $snapshot->evaluatedAt;

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
                    'created_at' => $snapshot->evaluatedAt,
                ];

                if (count($batch) === 500) {
                    DB::table((new PayrollReadinessSnapshotItemRecord)->getTable())->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table((new PayrollReadinessSnapshotItemRecord)->getTable())->insert($batch);
            }

            if ($position !== $snapshot->itemCount
                || $itemsHash !== $snapshot->itemsHash
                || $sourceRowCount !== $snapshot->sourceRowCount
                || $validationIssueCount !== $snapshot->validationIssueCount
                || $blockerCount !== $snapshot->blockerCount) {
                throw new LogicException('payroll_readiness_evidence_stream_changed');
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'SET CONSTRAINTS workforce_payroll_readiness_snapshots_complete, '
                    .'workforce_payroll_readiness_snapshot_items_complete IMMEDIATE',
                );
                DB::statement(
                    'SET CONSTRAINTS workforce_payroll_readiness_snapshots_complete, '
                    .'workforce_payroll_readiness_snapshot_items_complete DEFERRED',
                );
            }
        });
    }
}

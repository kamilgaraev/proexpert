<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\ExactDecimal;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Models\PayrollReadinessSnapshot;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class PayrollReadinessSnapshotRecorder
{
    public function record(object $period, string $ownerSourceHash, CarbonInterface $lockedAt): PayrollReadinessSnapshot
    {
        $currency = mb_strtoupper((string) ($period->currency ?? ''));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $ownerSourceHash) !== 1) {
            throw new DomainException('payroll_readiness_snapshot_identity_invalid');
        }

        $ownerRows = DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $period->organization_id)
            ->where('payroll_period_id', $period->id)
            ->orderBy('id')
            ->get();
        if ($ownerRows->isEmpty()) {
            throw new DomainException('payroll_readiness_source_rows_missing');
        }

        $rows = $ownerRows->map(static function (object $row): array {
            $payload = [
                'source_row_id' => (int) $row->id,
                'employee_id' => (int) $row->employee_id,
                'project_id' => (int) $row->project_id,
                'work_date' => (string) $row->work_date,
                'hours' => (string) $row->hours,
                'amount_minor' => ExactDecimal::minor((string) $row->amount),
            ];

            return [...$payload, 'source_hash' => hash('sha256', CanonicalJson::encode($payload))];
        })->all();
        $blockingIssueCount = DB::table('workforce_payroll_validation_issues')
            ->where('organization_id', $period->organization_id)
            ->where('payroll_period_id', $period->id)
            ->where('severity', 'blocking')
            ->whereNull('resolved_at')
            ->count();
        $snapshotPayload = [
            'organization_id' => (int) $period->organization_id,
            'payroll_period_id' => (int) $period->id,
            'project_id' => $period->project_id === null ? null : (int) $period->project_id,
            'period_from' => (string) $period->period_start,
            'period_to' => (string) $period->period_end,
            'currency' => $currency,
            'currency_source' => 'payroll_period',
            'owner_source_hash' => $ownerSourceHash,
            'row_count' => count($rows),
            'blocking_issue_count' => $blockingIssueCount,
            'locked_at' => $lockedAt->toAtomString(),
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'snapshot' => $snapshotPayload,
            'rows' => $rows,
        ]));

        $snapshot = PayrollReadinessSnapshot::query()->firstOrCreate(
            [
                'organization_id' => $snapshotPayload['organization_id'],
                'payroll_period_id' => $snapshotPayload['payroll_period_id'],
                'owner_source_hash' => $ownerSourceHash,
            ],
            [...$snapshotPayload, 'source_hash' => $sourceHash],
        );
        if (! hash_equals((string) $snapshot->source_hash, $sourceHash)) {
            throw new DomainException('payroll_readiness_snapshot_replay_conflict');
        }
        if (! $snapshot->wasRecentlyCreated) {
            return $snapshot;
        }

        $snapshot->rows()->createMany(array_map(
            static fn (array $row): array => [
                ...$row,
                'organization_id' => $snapshotPayload['organization_id'],
            ],
            $rows,
        ));

        return $snapshot;
    }
}

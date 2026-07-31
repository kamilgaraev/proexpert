<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Infrastructure\Persistence;

use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceWatermark;
use App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Models\BudgetingReportSourceCloseRecord;
use App\BusinessModules\Features\Budgeting\Models\BudgetingReportSourceWatermarkRecord;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class EloquentBudgetingReportSourceCloseStore implements BudgetingReportSourceCloseStore
{
    public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
    {
        return DB::transaction(function () use ($request): BudgetingReportSourceClose {
            $active = $this->activeCloseFor($request->identity);

            if ($request->restatesCloseId === null && $active instanceof BudgetingReportSourceCloseRecord) {
                throw new DomainException('budgeting_report_source_close_active_exists');
            }

            if ($request->restatesCloseId !== null) {
                if (!$active instanceof BudgetingReportSourceCloseRecord || $active->close_id !== $request->restatesCloseId) {
                    throw new DomainException('budgeting_report_source_close_restatement_target_invalid');
                }

                $active->forceFill([
                    'status' => BudgetingReportSourceCloseStatus::RESTATED->value,
                    'restated_by' => $request->approvedBy,
                    'restated_at' => $request->approvedAt,
                    'restated_by_close_id' => $request->closeId,
                ])->save();
            }

            $record = BudgetingReportSourceCloseRecord::query()->create([
                'close_id' => $request->closeId,
                ...$request->identity->toArray(),
                'formula_version' => $request->formulaVersion,
                'source_manifest' => $request->sourceManifest,
                'content_hash' => $request->contentHash,
                'approved_by' => $request->approvedBy,
                'approved_at' => $request->approvedAt,
                'retained_until' => $request->retainedUntil,
                'status' => BudgetingReportSourceCloseStatus::APPROVED->value,
                'restates_close_id' => $request->restatesCloseId,
            ]);

            foreach ($request->sourceWatermarks as $watermark) {
                BudgetingReportSourceWatermarkRecord::query()->create([
                    'close_id' => $record->close_id,
                    ...$watermark->toArray(),
                ]);
            }

            return $this->toDto($record->load('watermarks'));
        });
    }

    public function find(string $closeId): ?BudgetingReportSourceClose
    {
        $record = BudgetingReportSourceCloseRecord::query()
            ->with('watermarks')
            ->where('close_id', $closeId)
            ->first();

        return $record instanceof BudgetingReportSourceCloseRecord ? $this->toDto($record) : null;
    }

    private function activeCloseFor(BudgetingReportSourceCloseIdentity $identity): ?BudgetingReportSourceCloseRecord
    {
        return BudgetingReportSourceCloseRecord::query()
            ->where($identity->toArray())
            ->where('status', BudgetingReportSourceCloseStatus::APPROVED->value)
            ->lockForUpdate()
            ->first();
    }

    private function toDto(BudgetingReportSourceCloseRecord $record): BudgetingReportSourceClose
    {
        $watermarks = $record->watermarks
            ->sortBy('source')
            ->map(static fn (BudgetingReportSourceWatermarkRecord $watermark): BudgetingReportSourceWatermark => new BudgetingReportSourceWatermark(
                source: (string) $watermark->source,
                cutoffAt: DateTimeImmutable::createFromInterface($watermark->cutoff_at),
                watermark: (string) $watermark->watermark,
                sourceSchemaVersion: (string) $watermark->source_schema_version,
            ))
            ->values()
            ->all();

        return new BudgetingReportSourceClose(
            closeId: (string) $record->close_id,
            identity: new BudgetingReportSourceCloseIdentity(
                organizationId: (int) $record->organization_id,
                periodStart: $record->period_start->format('Y-m-d'),
                periodEnd: $record->period_end->format('Y-m-d'),
                scenarioIdentity: (string) $record->scenario_identity,
                planIdentity: (string) $record->plan_identity,
            ),
            sourceWatermarks: $watermarks,
            formulaVersion: (string) $record->formula_version,
            sourceManifest: $record->source_manifest,
            contentHash: (string) $record->content_hash,
            approvedBy: (int) $record->approved_by,
            approvedAt: DateTimeImmutable::createFromInterface($record->approved_at),
            retainedUntil: $record->retained_until === null ? null : DateTimeImmutable::createFromInterface($record->retained_until),
            status: BudgetingReportSourceCloseStatus::from((string) $record->status),
            restatesCloseId: $record->restates_close_id === null ? null : (string) $record->restates_close_id,
        );
    }
}

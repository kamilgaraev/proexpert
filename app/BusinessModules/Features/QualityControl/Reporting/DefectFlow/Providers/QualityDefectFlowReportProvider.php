<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Providers;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportSnapshotSealBackfill;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowSnapshot;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowSnapshotMaterializer;
use DateTimeImmutable;

final readonly class QualityDefectFlowReportProvider implements ReportDataProvider
{
    public function __construct(
        private QualityDefectFlowSnapshotMaterializer $materializer,
        private ReportSnapshotSealStore $seals,
        private ReportSnapshotSealBackfill $sealBackfill,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $snapshot = $this->materializer->materialize($context, $query);
        $progress->advance(100);
        $this->sealBackfill->ensureCovered('quality_defect_flow');

        return $this->reference($context, $snapshot, $query->definition->snapshotClassification);
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $quality = $this->quality($record);

        return new ReportResult(
            metadata: new ReportResultMetadata(
                snapshot: $snapshot,
                rowCount: (int) $record->row_count,
                generatedAt: $snapshot->generatedAt,
                staleAt: $snapshot->staleAt,
            ),
            totals: [
                'opening' => (int) $record->opening_count,
                'created' => (int) $record->created_count,
                'reopened' => (int) $record->reopened_count,
                'closed' => (int) $record->closed_count,
                'closing' => (int) $record->closing_count,
                'due' => (int) $record->due_count,
                'overdue' => (int) $record->overdue_count,
                'overdue_pct' => $record->overdue_pct,
                'mature_cohort' => (int) $record->mature_cohort_count,
                'first_pass' => (int) $record->first_pass_count,
                'mature_reopened' => (int) $record->mature_reopened_count,
                'reopen_rate' => $record->reopen_rate,
                'first_pass_yield' => $record->first_pass_yield,
            ],
            freshness: $this->freshness($snapshot),
            quality: $quality,
            provenance: new ReportProvenance(
                sourceOfTruth: 'quality_defect_transitions',
                sourceRefs: [$this->sourceRef($record)],
                sourceHash: $snapshot->sourceHash,
                externalConfirmationRole: null,
            ),
            rowSchema: $this->rowSchema(),
            capabilities: [
                'drill_down' => true,
                'evidence_redaction' => true,
                'snapshot_export_parity' => true,
            ],
        );
    }

    private function reference(
        ReportExecutionContext $context,
        QualityDefectFlowSnapshot $snapshot,
        \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification $classification,
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            kind: 'quality_defect_flow',
            id: (string) $snapshot->id,
            scope: $context->scope,
            definitionHash: new Sha256Hash((string) $snapshot->definition_hash),
            formulaVersion: (string) $snapshot->formula_version,
            sourceHash: new Sha256Hash((string) $snapshot->source_hash),
            generatedAt: DateTimeImmutable::createFromInterface($snapshot->generated_at),
            staleAt: DateTimeImmutable::createFromInterface($snapshot->stale_at),
            watermarks: ['quality_defect_transitions' => $snapshot->source_watermark->toAtomString()],
            classification: $classification,
            seal: $this->seals->get('quality_defect_flow', (string) $snapshot->id),
        );
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $reference): QualityDefectFlowSnapshot
    {
        $record = QualityDefectFlowSnapshot::query()
            ->whereKey($reference->id)
            ->where('organization_id', $context->scope->organizationId)
            ->where('source_hash', $reference->sourceHash->value)
            ->where('definition_hash', $reference->definitionHash->value)
            ->first();
        if (! $record instanceof QualityDefectFlowSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function sourceRef(QualityDefectFlowSnapshot $snapshot): ReportSourceRef
    {
        return new ReportSourceRef(
            source: 'quality_defect_transitions',
            snapshotKind: 'quality_defect_flow',
            snapshotId: 's_'.strtolower((string) $snapshot->id),
            schemaVersion: 'quality_defect_flow_v1',
            watermark: 'w_'.$snapshot->source_watermark->format('YmdHis'),
            rowCount: (int) $snapshot->row_count,
            hash: new Sha256Hash((string) $snapshot->input_hash),
        );
    }

    private function quality(QualityDefectFlowSnapshot $snapshot): ReportQuality
    {
        $emptyMatureCohort = (int) $snapshot->mature_cohort_count === 0;
        $emptyDueCohort = (int) $snapshot->due_count === 0;
        $sourceComplete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0;
        $complete = $sourceComplete && ! $emptyMatureCohort && ! $emptyDueCohort;
        $warnings = [];
        if ((int) $snapshot->gap_count > 0) {
            $warnings[] = new ReportWarning('DEFECT_HISTORY_GAP', ReportWarningSeverity::CRITICAL, null, (int) $snapshot->gap_count);
        }
        if ((int) $snapshot->unknown_count > 0) {
            $warnings[] = new ReportWarning('DEFECT_CLOSURE_EVIDENCE_MISSING', ReportWarningSeverity::CRITICAL, 'closed', (int) $snapshot->unknown_count);
        }
        if ($emptyMatureCohort) {
            $warnings[] = new ReportWarning(
                'DEFECT_MATURE_COHORT_EMPTY',
                ReportWarningSeverity::WARNING,
                'first_pass_yield',
                0,
            );
        }
        if ($emptyDueCohort) {
            $warnings[] = new ReportWarning(
                'DEFECT_DUE_COHORT_EMPTY',
                ReportWarningSeverity::WARNING,
                'overdue_pct',
                0,
            );
        }

        return new ReportQuality(
            status: $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            coverage: new ReportCoverage(
                (string) $snapshot->projected_count,
                (string) $snapshot->eligible_count,
                $this->ratio((int) $snapshot->projected_count, (int) $snapshot->eligible_count),
            ),
            warnings: $warnings,
            unmatchedCount: (int) $snapshot->gap_count,
            reconciliation: $sourceComplete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            unknownMetrics: [
                ...((int) $snapshot->unknown_count > 0 || $emptyMatureCohort
                    ? ['first_pass_yield', 'reopen_rate']
                    : []),
                ...($emptyDueCohort ? ['overdue_pct'] : []),
            ],
            excludedSources: [],
        );
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }

    private function ratio(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $scaled = intdiv($numerator * 10_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

    private function rowSchema(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id],
            ['row_key', 'cohort_date', 'project_id', 'contractor_id', 'schedule_task_id', 'quality_defect_id', 'event_version', 'severity', 'status', 'created', 'reopened', 'closed', 'closing', 'cycle_days', 'due_date', 'evidence_refs'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Providers;

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
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportSnapshotSealBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionSnapshot;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\WorkforceAdmissionSnapshotMaterializer;
use DateTimeImmutable;

final readonly class WorkforceAdmissionReportProvider implements ReportDataProvider
{
    public function __construct(
        private WorkforceAdmissionSnapshotMaterializer $materializer,
        private ReportSnapshotSealStore $seals,
        private ReportSnapshotSealBackfill $sealBackfill,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $record = $this->materializer->materialize($context, $query);
        $progress->advance(100);
        if ($query->definition->snapshotClassification === ReportSnapshotClassification::OFFICIAL) {
            $this->sealBackfill->ensureCovered('workforce_admission');
        }

        return new ReportSnapshotRef(
            kind: 'workforce_admission',
            id: (string) $record->id,
            scope: $context->scope,
            definitionHash: new Sha256Hash((string) $record->definition_hash),
            formulaVersion: (string) $record->formula_version,
            sourceHash: new Sha256Hash((string) $record->source_hash),
            generatedAt: DateTimeImmutable::createFromInterface($record->generated_at),
            staleAt: DateTimeImmutable::createFromInterface($record->stale_at),
            watermarks: ['workforce_admission' => $record->source_watermark->toAtomString()],
            classification: $query->definition->snapshotClassification,
            seal: $query->definition->snapshotClassification === ReportSnapshotClassification::OFFICIAL
                ? $this->seals->get('workforce_admission', (string) $record->id)
                : null,
        );
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $quality = $this->quality($record);

        return new ReportResult(
            new ReportResultMetadata($snapshot, (int) $record->row_count, $snapshot->generatedAt, $snapshot->staleAt),
            [
                'evaluated_people' => (int) $record->evaluated_people,
                'admitted_people' => (int) $record->admitted_people,
                'partial_people' => (int) $record->partial_people,
                'not_admitted_people' => (int) $record->not_admitted_people,
                'compliance_pct' => $this->percent((int) $record->admitted_people, (int) $record->evaluated_people),
                'blocker_count' => (int) $record->blocker_count,
                'expiring_count' => (int) $record->expiring_count,
                'unverified_coverage' => (int) $record->unverified_count,
            ],
            $this->freshness($snapshot),
            $quality,
            new ReportProvenance(
                'safety_compliance_evidence',
                [new ReportSourceRef(
                    'safety_compliance_evidence',
                    'workforce_admission',
                    's_'.strtolower((string) $record->id),
                    'workforce_admission_v1',
                    'w_'.$record->source_watermark->format('YmdHis'),
                    (int) $record->row_count,
                    new Sha256Hash((string) $record->input_hash),
                )],
                $snapshot->sourceHash,
                null,
            ),
            array_map(
                static fn (string $id): array => ['id' => $id],
                [
                    'row_key',
                    'snapshot_date',
                    'project_id',
                    'safety_site_id',
                    'workforce_assignment_id',
                    'employee_id',
                    'requirement_code',
                    'requirement_type',
                    'status',
                    'mandatory',
                    'blocked',
                    'verified',
                    'valid_until',
                    'evidence_id',
                    ...($context->visibility->canViewSensitive ? ['medical_details'] : []),
                ],
            ),
            ['drill_down' => true, 'medical_redaction' => true, 'snapshot_export_parity' => true],
        );
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): SafetyAdmissionSnapshot
    {
        $record = SafetyAdmissionSnapshot::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->where('source_hash', $snapshot->sourceHash->value)
            ->where('definition_hash', $snapshot->definitionHash->value)
            ->first();
        if (! $record instanceof SafetyAdmissionSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function quality(SafetyAdmissionSnapshot $snapshot): ReportQuality
    {
        $sourceComplete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0;
        $warnings = [];
        if ((int) $snapshot->unknown_count > 0) {
            $warnings[] = new ReportWarning('ADMISSION_EVIDENCE_UNVERIFIED', ReportWarningSeverity::CRITICAL, 'unverified_coverage', (int) $snapshot->unknown_count);
        }

        return new ReportQuality(
            $sourceComplete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage(
                (string) $snapshot->projected_count,
                (string) $snapshot->eligible_count,
                $this->ratio((int) $snapshot->projected_count, (int) $snapshot->eligible_count),
            ),
            $warnings,
            (int) $snapshot->gap_count,
            $sourceComplete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            [],
            [],
        );
    }

    private function ratio(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $scaled = intdiv($numerator * 10_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

    private function percent(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $scaled = intdiv($numerator * 10_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%02d', intdiv($scaled, 100), $scaled % 100);
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }
}

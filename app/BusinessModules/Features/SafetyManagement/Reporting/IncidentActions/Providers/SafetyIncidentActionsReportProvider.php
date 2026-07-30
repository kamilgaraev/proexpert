<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Providers;

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
use App\BusinessModules\Core\Reporting\Infrastructure\Security\CanonicalReportSnapshotSealer;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentSnapshot;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyIncidentSnapshotMaterializer;
use DateTimeImmutable;

final readonly class SafetyIncidentActionsReportProvider implements ReportDataProvider
{
    public function __construct(
        private SafetyIncidentSnapshotMaterializer $materializer,
        private CanonicalReportSnapshotSealer $sealer,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $record = $this->materializer->materialize($context, $query);
        $progress->advance(100);

        return new ReportSnapshotRef(
            kind: 'safety_incident_actions',
            id: (string) $record->id,
            scope: $context->scope,
            definitionHash: new Sha256Hash((string) $record->definition_hash),
            formulaVersion: (string) $record->formula_version,
            sourceHash: new Sha256Hash((string) $record->source_hash),
            generatedAt: DateTimeImmutable::createFromInterface($record->generated_at),
            staleAt: DateTimeImmutable::createFromInterface($record->stale_at),
            watermarks: ['safety_transitions' => $record->source_watermark->toAtomString()],
            classification: $query->definition->snapshotClassification,
            seal: $this->sealer->seal(
                (string) $record->id,
                'safety_incident_actions',
                DateTimeImmutable::createFromInterface($record->generated_at),
                new Sha256Hash((string) $record->source_hash),
                DateTimeImmutable::createFromInterface($record->sealed_at),
            ),
        );
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $quality = $this->quality($record);

        return new ReportResult(
            new ReportResultMetadata($snapshot, (int) $record->row_count, $snapshot->generatedAt, $snapshot->staleAt),
            [
                'incident_count' => (int) $record->incident_count,
                'violation_count' => (int) $record->violation_count,
                'corrective_action_due' => (int) $record->action_due_count,
                'corrective_action_overdue' => (int) $record->action_overdue_count,
                'on_time_closure_count' => (int) $record->action_closed_on_time_count,
                'on_time_closure_pct' => $this->percent(
                    (int) $record->action_closed_on_time_count,
                    (int) $record->action_due_count,
                ),
                'opening_backlog' => (int) $record->opening_backlog_count,
                'closing_backlog' => (int) $record->closing_backlog_count,
                'exposure_hours' => $record->exposure_complete ? (string) $record->exposure_hours : null,
                'incident_frequency' => $record->exposure_complete ? $record->incident_frequency : null,
            ],
            $this->freshness($snapshot),
            $quality,
            new ReportProvenance(
                'safety_transition_events',
                [new ReportSourceRef(
                    'safety_transition_events',
                    'safety_incident_actions',
                    's_'.strtolower((string) $record->id),
                    'safety_incident_actions_v1',
                    'w_'.$record->source_watermark->format('YmdHis'),
                    (int) $record->row_count,
                    new Sha256Hash((string) $record->input_hash),
                )],
                $snapshot->sourceHash,
                null,
            ),
            array_map(
                static fn (string $id): array => ['id' => $id],
                ['row_key', 'event_date', 'project_id', 'safety_site_id', 'contractor_id', 'subject_type', 'subject_id', 'event_version', 'category', 'severity', 'status', 'owner_user_id', 'due_date', 'created', 'reopened', 'closed', 'closure_verified', 'closure_days', 'evidence_id'],
            ),
            ['drill_down' => true, 'evidence_redaction' => true, 'snapshot_export_parity' => true],
        );
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): SafetyIncidentSnapshot
    {
        $record = SafetyIncidentSnapshot::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->where('source_hash', $snapshot->sourceHash->value)
            ->where('definition_hash', $snapshot->definitionHash->value)
            ->first();
        if (! $record instanceof SafetyIncidentSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function quality(SafetyIncidentSnapshot $snapshot): ReportQuality
    {
        $sourceComplete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0;
        $complete = $sourceComplete && (bool) $snapshot->exposure_complete;
        $warnings = [];
        if (! (bool) $snapshot->exposure_complete) {
            $warnings[] = new ReportWarning('SAFETY_EXPOSURE_INCOMPLETE', ReportWarningSeverity::CRITICAL, 'incident_frequency', 1);
        }
        if ((int) $snapshot->unknown_count > 0) {
            $warnings[] = new ReportWarning('SAFETY_SITE_OR_EVIDENCE_UNKNOWN', ReportWarningSeverity::CRITICAL, null, (int) $snapshot->unknown_count);
        }

        $denominator = (int) $snapshot->eligible_count;
        $numerator = (int) $snapshot->projected_count;

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage((string) $numerator, (string) $denominator, $this->ratio($numerator, $denominator)),
            $warnings,
            (int) $snapshot->gap_count,
            $sourceComplete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            ! (bool) $snapshot->exposure_complete ? ['incident_frequency'] : [],
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
        $scaled = intdiv($numerator * 1_000_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }
}

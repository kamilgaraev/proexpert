<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Providers;

use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
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
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessRow;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessSnapshot;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services\HandoverReadinessSnapshotMaterializer;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HandoverReadinessReportProvider implements ReportDataProvider
{
    public function __construct(
        private HandoverReadinessSnapshotMaterializer $materializer,
        private CanonicalReportSourceHashBuilder $identities,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $provisional = $this->materializer->materialize($context, $query, $progress);
        $progress->advance(99);
        $canonical = $this->identities->build(
            $query,
            $provisional,
            $this->result($context, $provisional),
        );
        $progress->advance(100);

        return new ReportSnapshotRef(
            kind: $provisional->kind,
            id: $provisional->id,
            scope: $provisional->scope,
            definitionHash: $provisional->definitionHash,
            formulaVersion: $provisional->formulaVersion,
            sourceHash: $canonical,
            generatedAt: $provisional->generatedAt,
            staleAt: $provisional->staleAt,
            watermarks: $provisional->watermarks,
            classification: $provisional->classification,
            seal: $provisional->seal,
            materializedSourceHash: $provisional->materializedSourceHash,
        );
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        if (
            $snapshot->kind !== 'handover_readiness'
            || $context->scope->organizationId !== $snapshot->scope->organizationId
        ) {
            throw new InvalidArgumentException('handover_readiness_snapshot_invalid');
        }
        $record = HandoverReadinessSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->firstOrFail();
        $totals = HandoverReadinessRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->selectRaw('COUNT(*) AS gate_count')
            ->selectRaw('SUM(CASE WHEN ready THEN 1 ELSE 0 END) AS ready_gate_count')
            ->selectRaw('SUM(open_hard_blocker_count) AS open_hard_blocker_count')
            ->selectRaw('SUM(attempt_count) AS attempt_count')
            ->selectRaw('SUM(successful_result_count) AS successful_result_count')
            ->first();
        $ready = (int) ($totals?->ready_gate_count ?? 0);
        $rowCount = (int) $record->row_count;
        $quality = new ReportQuality(
            ReportQualityStatus::COMPLETE,
            new ReportCoverage((string) $ready, (string) $rowCount, $rowCount === 0 ? null : bcdiv((string) $ready, (string) $rowCount, 8)),
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );

        return new ReportResult(
            new ReportResultMetadata(
                $snapshot,
                $rowCount,
                DateTimeImmutable::createFromInterface($record->generated_at),
                $record->stale_at === null ? null : DateTimeImmutable::createFromInterface($record->stale_at),
            ),
            [
                'gate_count' => $rowCount,
                'ready_gate_count' => $ready,
                'not_ready_gate_count' => max(0, $rowCount - $ready),
                'open_hard_blocker_count' => (int) ($totals?->open_hard_blocker_count ?? 0),
                'attempt_count' => (int) ($totals?->attempt_count ?? 0),
                'successful_result_count' => (int) ($totals?->successful_result_count ?? 0),
            ],
            $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable()
                ? ReportFreshnessStatus::STALE
                : ReportFreshnessStatus::FRESH,
            $quality,
            new ReportProvenance(
                'handover_evidence',
                [new ReportSourceRef(
                    'handover_acceptance',
                    'handover_evidence',
                    'snapshot_'.strtolower($snapshot->id),
                    'handover_readiness_v1',
                    'event_'.(string) ($record->watermarks['last_event_id'] ?? 0),
                    $rowCount,
                    new Sha256Hash((string) $record->source_hash),
                )],
                $snapshot->sourceHash,
                null,
            ),
            [
                ['id' => 'project_id', 'type' => 'integer'], ['id' => 'acceptance_scope_id', 'type' => 'integer'],
                ['id' => 'location_id', 'type' => 'integer'], ['id' => 'package_id', 'type' => 'integer'],
                ['id' => 'gate_code', 'type' => 'string'], ['id' => 'due_on', 'type' => 'date'],
                ['id' => 'mandatory_completeness', 'type' => 'decimal'], ['id' => 'document_completeness', 'type' => 'decimal'],
                ['id' => 'open_hard_blocker_count', 'type' => 'integer'], ['id' => 'attempt_count', 'type' => 'integer'],
                ['id' => 'successful_result_count', 'type' => 'integer'], ['id' => 'ready', 'type' => 'boolean'],
            ],
            ['drill_down' => true, 'export_formats' => ['csv', 'xlsx']],
        );
    }
}

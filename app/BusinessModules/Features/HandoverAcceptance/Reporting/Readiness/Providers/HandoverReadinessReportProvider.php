<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Providers;

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
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessRow;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessSnapshot;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services\HandoverReadinessSnapshotMaterializer;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HandoverReadinessReportProvider implements ReportDataProvider
{
    public function __construct(private HandoverReadinessSnapshotMaterializer $materializer)
    {
    }

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $progress->advance(10);
        $snapshot = $this->materializer->materialize($context, $query);
        $progress->advance(100);

        return $snapshot;
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
        $ready = HandoverReadinessRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('ready', true)
            ->count();
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
            ['gate_count' => $rowCount, 'ready_gate_count' => $ready],
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
                    $snapshot->sourceHash,
                )],
                $snapshot->sourceHash,
                null,
            ),
            [
                ['id' => 'project_id', 'type' => 'integer'],
                ['id' => 'gate_code', 'type' => 'string'],
                ['id' => 'mandatory_completeness', 'type' => 'decimal'],
                ['id' => 'document_completeness', 'type' => 'decimal'],
                ['id' => 'open_hard_blocker_count', 'type' => 'integer'],
                ['id' => 'ready', 'type' => 'boolean'],
            ],
            ['drill_down' => true, 'export_formats' => ['csv', 'xlsx']],
        );
    }
}

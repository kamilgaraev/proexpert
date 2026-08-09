<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Providers;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardRow;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardSnapshot;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorScorecardSnapshotMaterializer;
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
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ContractorScorecardReportProvider implements ReportDataProvider
{
    public function __construct(private ContractorScorecardSnapshotMaterializer $materializer)
    {
    }

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $snapshot = $this->materializer->materialize($context, $query, $progress);
        $progress->advance(100);

        return $snapshot;
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        if (
            $snapshot->kind !== 'contractor_scorecard'
            || $context->scope->organizationId !== $snapshot->scope->organizationId
        ) {
            throw new InvalidArgumentException('contractor_scorecard_snapshot_invalid');
        }
        $record = ContractorScorecardSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->firstOrFail();
        $rows = ContractorScorecardRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
        $rowCount = (int) $record->row_count;
        $covered = (clone $rows)->whereNotNull('component_mean')->count();
        $qualityStatus = $covered === $rowCount
            ? ReportQualityStatus::COMPLETE
            : ReportQualityStatus::PARTIAL;

        return new ReportResult(
            new ReportResultMetadata(
                $snapshot,
                $rowCount,
                DateTimeImmutable::createFromInterface($record->generated_at),
                $record->stale_at === null ? null : DateTimeImmutable::createFromInterface($record->stale_at),
            ),
            ['component_row_count' => $rowCount, 'covered_component_count' => $covered],
            $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable()
                ? ReportFreshnessStatus::STALE
                : ReportFreshnessStatus::FRESH,
            new ReportQuality(
                $qualityStatus,
                new ReportCoverage(
                    (string) $covered,
                    (string) $rowCount,
                    $rowCount === 0 ? null : bcdiv((string) $covered, (string) $rowCount, 8),
                ),
                [],
                $rowCount - $covered,
                $covered === $rowCount
                    ? ReportReconciliationStatus::MATCHED
                    : ReportReconciliationStatus::MISMATCH,
                $covered === $rowCount ? [] : ['component_mean'],
                [],
            ),
            new ReportProvenance(
                'contractor_evidence',
                [new ReportSourceRef(
                    'contractor_evidence',
                    'contractor_scorecard_sources',
                    'snapshot_'.strtolower($snapshot->id),
                    'contractor_scorecard_v1',
                    'tuple_'.substr((string) $record->source_tuple_hash, 0, 32),
                    $rowCount,
                    $snapshot->sourceHash,
                )],
                $snapshot->sourceHash,
                null,
            ),
            [
                ['id' => 'profile_id', 'type' => 'integer'],
                ['id' => 'category_id', 'type' => 'integer'],
                ['id' => 'component_code', 'type' => 'string'],
                ['id' => 'unit_code', 'type' => 'string'],
                ['id' => 'component_mean', 'type' => 'decimal'],
                ['id' => 'sample_size', 'type' => 'integer'],
                ['id' => 'coverage', 'type' => 'decimal'],
            ],
            [
                'composite_score' => false,
                'drill_down' => true,
                'export_formats' => ['csv', 'xlsx'],
            ],
        );
    }
}

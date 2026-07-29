<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
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
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\PortfolioLiquidityRow;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioProjectionResult;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\BudgetingPortfolioSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquidityProjection;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\ProjectPortfolioHealthProjection;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class BudgetingPortfolioProjectionService
{
    public const HEALTH_CODE = 'project_portfolio_health';
    public const LIQUIDITY_CODE = 'portfolio_liquidity';

    public function persistHealth(
        ReportExecutionContext $context,
        ReportQuery $query,
        ProjectPortfolioProjectionResult $projection,
        Sha256Hash $sourceHash,
        array $watermarks,
        array $sourceRefs,
        ReportFreshnessStatus $freshness = ReportFreshnessStatus::FRESH,
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query, self::HEALTH_CODE);
        $this->assertSourceRefs($sourceRefs);
        $generatedAt = $query->asOf;
        $id = (string) Str::ulid();
        $quality = $this->healthQuality($projection);

        DB::transaction(function () use ($context, $query, $projection, $sourceHash, $watermarks, $sourceRefs, $freshness, $generatedAt, $id, $quality): void {
            BudgetingPortfolioSnapshot::query()->create([
                'id' => $id,
                'organization_id' => $context->scope->organizationId,
                'report_code' => self::HEALTH_CODE,
                'as_of' => $query->asOf,
                'definition_hash' => $query->definition->definitionHash->value,
                'source_hash' => $sourceHash->value,
                'query_hash' => $query->queryHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'quality_status' => $quality->status->value,
                'freshness_status' => $freshness->value,
                'totals' => $projection->totalsByCurrency,
                'watermarks' => $watermarks,
                'source_refs' => $this->serializeSourceRefs($sourceRefs),
                'row_count' => count($projection->rows),
                'generated_at' => $generatedAt,
                'stale_at' => $freshness === ReportFreshnessStatus::STALE ? $generatedAt : null,
            ]);

            foreach ($projection->rows as $row) {
                ProjectPortfolioHealthProjection::query()->create([
                    'organization_id' => $context->scope->organizationId,
                    'snapshot_id' => $id,
                    'project_id' => $row->projectId,
                    'project_name' => $row->projectName,
                    'currency' => $row->currency,
                    'as_of' => $row->asOf,
                    'risk_rank' => $row->riskRank,
                    'risk_level' => $row->riskLevel,
                    'revenue' => $row->revenue,
                    'cost' => $row->cost,
                    'margin' => $row->margin,
                    'margin_percent' => $row->marginPercent,
                    'wip' => $row->wip,
                    'ftc' => $row->ftc,
                    'eac' => $row->eac,
                    'ctc' => $row->ctc,
                    'row_key' => $row->rowKey,
                    'source_refs' => $row->sourceRefs,
                ]);
            }
        });

        return $this->ref($context, BudgetingPortfolioSnapshot::query()->findOrFail($id));
    }

    public function persistLiquidity(
        ReportExecutionContext $context,
        ReportQuery $query,
        array $rows,
        Sha256Hash $sourceHash,
        array $watermarks,
        array $sourceRefs,
        ReportFreshnessStatus $freshness = ReportFreshnessStatus::FRESH,
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query, self::LIQUIDITY_CODE);
        $this->assertSourceRefs($sourceRefs);
        foreach ($rows as $row) {
            if (!$row instanceof PortfolioLiquidityRow) {
                throw new InvalidArgumentException('portfolio_liquidity_projection_rows_invalid');
            }
        }

        $id = (string) Str::ulid();
        $generatedAt = $query->asOf;
        $totals = $this->liquidityTotals($rows);
        $quality = $this->liquidityQuality($rows);

        DB::transaction(function () use ($context, $query, $rows, $sourceHash, $watermarks, $sourceRefs, $freshness, $id, $generatedAt, $totals, $quality): void {
            BudgetingPortfolioSnapshot::query()->create([
                'id' => $id,
                'organization_id' => $context->scope->organizationId,
                'report_code' => self::LIQUIDITY_CODE,
                'as_of' => $query->asOf,
                'definition_hash' => $query->definition->definitionHash->value,
                'source_hash' => $sourceHash->value,
                'query_hash' => $query->queryHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'quality_status' => $quality->status->value,
                'freshness_status' => $freshness->value,
                'totals' => $totals,
                'watermarks' => $watermarks,
                'source_refs' => $this->serializeSourceRefs($sourceRefs),
                'row_count' => count($rows),
                'generated_at' => $generatedAt,
                'stale_at' => $freshness === ReportFreshnessStatus::STALE ? $generatedAt : null,
            ]);

            foreach ($rows as $row) {
                PortfolioLiquidityProjection::query()->create([
                    'organization_id' => $context->scope->organizationId,
                    'snapshot_id' => $id,
                    'forecast_date' => $row->forecastDate,
                    'project_id' => $row->projectId,
                    'project_name' => $row->projectName,
                    'currency' => $row->currency,
                    'scenario' => $row->scenario,
                    'opening' => $row->opening,
                    'inflow' => $row->inflow,
                    'outflow' => $row->outflow,
                    'closing' => $row->closing,
                    'gap' => $row->gap,
                    'quality_status' => $row->qualityStatus,
                    'duplicate_source_count' => $row->duplicateSourceCount,
                    'row_key' => $row->rowKey,
                    'source_refs' => $row->sourceRefs,
                ]);
            }
        });

        return $this->ref($context, BudgetingPortfolioSnapshot::query()->findOrFail($id));
    }

    public function materializePrepared(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
        string $code,
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query, $code);
        $record = BudgetingPortfolioSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('report_code', $code)
            ->where('query_hash', $query->queryHash->value)
            ->where('definition_hash', $query->definition->definitionHash->value)
            ->latest('generated_at')
            ->first();

        if (!$record instanceof BudgetingPortfolioSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        $progress->advance(100);

        return $this->ref($context, $record);
    }

    public function result(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $code,
    ): ReportResult {
        $record = BudgetingPortfolioSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->where('report_code', $code)
            ->first();
        if (!$record instanceof BudgetingPortfolioSnapshot
            || !hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        $quality = $this->qualityFromRecord($record);
        $sourceRefs = $this->hydrateSourceRefs($record->source_refs);

        return new ReportResult(
            metadata: new ReportResultMetadata(
                $snapshot,
                (int) $record->row_count,
                $snapshot->generatedAt,
                $snapshot->staleAt,
            ),
            totals: $record->totals,
            freshness: ReportFreshnessStatus::from((string) $record->freshness_status),
            quality: $quality,
            provenance: new ReportProvenance(
                'budgeting_owner_snapshot',
                $sourceRefs,
                $snapshot->sourceHash,
                null,
            ),
            rowSchema: $this->rowSchema($code),
            capabilities: [
                'formats' => $code === self::HEALTH_CODE ? ['csv', 'xlsx', 'pdf'] : ['csv', 'xlsx'],
                'drill_down' => true,
            ],
        );
    }

    private function assertQuery(ReportExecutionContext $context, ReportQuery $query, string $code): void
    {
        if ($query->definition->code !== $code
            || $query->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
    }

    private function ref(ReportExecutionContext $context, BudgetingPortfolioSnapshot $record): ReportSnapshotRef
    {
        $generatedAt = new DateTimeImmutable((string) $record->generated_at);
        $staleAt = $record->stale_at === null ? null : new DateTimeImmutable((string) $record->stale_at);

        return new ReportSnapshotRef(
            kind: (string) $record->report_code,
            id: (string) $record->getKey(),
            scope: $context->scope,
            definitionHash: new Sha256Hash((string) $record->definition_hash),
            formulaVersion: (string) $record->formula_version,
            sourceHash: new Sha256Hash((string) $record->source_hash),
            generatedAt: $generatedAt,
            staleAt: $staleAt,
            watermarks: [
                ...$record->watermarks,
                'query_hash' => (string) $record->query_hash,
            ],
            classification: $context->scope->organizationId > 0
                ? \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OPERATIONAL
                : throw new InvalidArgumentException('portfolio_snapshot_scope_invalid'),
            seal: null,
        );
    }

    private function serializeSourceRefs(array $sourceRefs): array
    {
        return array_map(
            static function (ReportSourceRef $source): array {
                return [
                    'source' => $source->source,
                    'snapshot_kind' => $source->snapshotKind,
                    'snapshot_id' => $source->snapshotId,
                    'schema_version' => $source->schemaVersion,
                    'watermark' => $source->watermark,
                    'row_count' => $source->rowCount,
                    'hash' => $source->hash->value,
                ];
            },
            $sourceRefs,
        );
    }

    private function assertSourceRefs(array $sourceRefs): void
    {
        if ($sourceRefs === [] || !array_is_list($sourceRefs)) {
            throw new InvalidArgumentException('portfolio_source_refs_invalid');
        }

        foreach ($sourceRefs as $sourceRef) {
            if (!$sourceRef instanceof ReportSourceRef) {
                throw new InvalidArgumentException('portfolio_source_refs_invalid');
            }
        }
    }

    private function hydrateSourceRefs(array $sourceRefs): array
    {
        return array_map(
            static fn (array $source): ReportSourceRef => new ReportSourceRef(
                (string) $source['source'],
                (string) $source['snapshot_kind'],
                (string) $source['snapshot_id'],
                (string) $source['schema_version'],
                (string) $source['watermark'],
                (int) $source['row_count'],
                new Sha256Hash((string) $source['hash']),
            ),
            $sourceRefs,
        );
    }

    private function healthQuality(ProjectPortfolioProjectionResult $projection): ReportQuality
    {
        return new ReportQuality(
            $projection->rows === [] ? ReportQualityStatus::PARTIAL : ReportQualityStatus::COMPLETE,
            new ReportCoverage((string) count($projection->rows), (string) count($projection->rows), count($projection->rows) === 0 ? null : '1.00000000'),
            $projection->rows === [] ? [new ReportWarning('SOURCE_EMPTY', ReportWarningSeverity::CRITICAL, null, 0)] : [],
            0,
            $projection->rows === [] ? ReportReconciliationStatus::NOT_APPLICABLE : ReportReconciliationStatus::MATCHED,
            $projection->rows === [] ? ['source_coverage'] : [],
            $projection->rows === [] ? ['owner_snapshot'] : [],
        );
    }

    private function liquidityQuality(array $rows): ReportQuality
    {
        $duplicates = array_sum(array_map(
            static fn (PortfolioLiquidityRow $row): int => $row->duplicateSourceCount,
            $rows,
        ));

        $empty = $rows === [];

        return new ReportQuality(
            !$empty && $duplicates === 0 ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage((string) count($rows), (string) count($rows), count($rows) === 0 ? null : '1.00000000'),
            $empty ? [new ReportWarning('SOURCE_EMPTY', ReportWarningSeverity::CRITICAL, null, 0)] : [],
            $duplicates,
            $empty ? ReportReconciliationStatus::NOT_APPLICABLE : ($duplicates === 0 ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH),
            $empty ? ['source_coverage'] : [],
            $empty ? ['owner_snapshot'] : ($duplicates === 0 ? [] : ['duplicate_cash_flow']),
        );
    }

    private function qualityFromRecord(BudgetingPortfolioSnapshot $record): ReportQuality
    {
        $count = (int) $record->row_count;
        $empty = $count === 0;

        return new ReportQuality(
            ReportQualityStatus::from((string) $record->quality_status),
            new ReportCoverage((string) $count, (string) $count, $count === 0 ? null : '1.00000000'),
            $empty ? [new ReportWarning('SOURCE_EMPTY', ReportWarningSeverity::CRITICAL, null, 0)] : [],
            0,
            $empty ? ReportReconciliationStatus::NOT_APPLICABLE : ReportReconciliationStatus::MATCHED,
            $empty ? ['source_coverage'] : [],
            $empty ? ['owner_snapshot'] : [],
        );
    }

    private function liquidityTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->currency] ??= ['inflow' => '0.00', 'outflow' => '0.00', 'closing' => '0.00'];
            $totals[$row->currency]['inflow'] = \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal::add(
                $totals[$row->currency]['inflow'],
                $row->inflow,
            );
            $totals[$row->currency]['outflow'] = \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal::add(
                $totals[$row->currency]['outflow'],
                $row->outflow,
            );
            $totals[$row->currency]['closing'] = $row->closing;
        }
        ksort($totals, SORT_STRING);

        return $totals;
    }

    private function rowSchema(string $code): array
    {
        $ids = $code === self::HEALTH_CODE
            ? ['project', 'currency', 'revenue', 'cost', 'margin', 'margin_percent', 'wip', 'ftc', 'eac', 'ctc', 'risk']
            : ['date', 'project', 'scenario', 'currency', 'opening', 'inflow', 'outflow', 'closing', 'gap', 'quality'];

        return array_map(static fn (string $id): array => ['id' => $id], $ids);
    }
}

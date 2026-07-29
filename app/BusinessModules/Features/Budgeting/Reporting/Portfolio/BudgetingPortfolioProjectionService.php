<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
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
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\WipForecastLine;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\PortfolioLiquidityRow;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioProjectionResult;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\BudgetingPortfolioSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquidityProjection;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\ProjectPortfolioHealthProjection;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use App\BusinessModules\Features\Budgeting\Services\CashGapOpeningBalanceService;
use App\BusinessModules\Features\Budgeting\Services\CfoProjectPortfolioAggregator;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportService;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportService;
use App\BusinessModules\Features\Budgeting\Services\WipForecastReportService;
use App\Models\ContractPerformanceAct;
use App\Models\Project;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class BudgetingPortfolioProjectionService
{
    public const HEALTH_CODE = 'project_portfolio_health';

    public const LIQUIDITY_CODE = 'portfolio_liquidity';

    public function __construct(
        private ProjectMarginReportService $marginReports,
        private WipForecastReportService $wipReports,
        private PlanFactReportService $planFactReports,
        private PaymentCalendarSourceService $calendarSources,
        private CashGapOpeningBalanceService $openingBalances,
        private CashGapForecastService $cashGapForecasts,
        private CfoProjectPortfolioAggregator $portfolioAggregator,
    ) {}

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
        array $qualityGaps = [],
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query, self::LIQUIDITY_CODE);
        $this->assertSourceRefs($sourceRefs);
        foreach ($rows as $row) {
            if (! $row instanceof PortfolioLiquidityRow) {
                throw new InvalidArgumentException('portfolio_liquidity_projection_rows_invalid');
            }
        }

        $id = (string) Str::ulid();
        $generatedAt = $query->asOf;
        $totals = $this->liquidityTotals($rows);
        $duplicateCount = array_sum(array_map(
            static fn (PortfolioLiquidityRow $row): int => $row->duplicateSourceCount,
            $rows,
        ));
        $totals['quality'] = [
            'gaps' => array_values($qualityGaps),
            'duplicate_source_count' => $duplicateCount,
        ];
        $quality = $this->liquidityQuality($rows, $qualityGaps);

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
                    'quality_gaps' => $row->qualityGaps,
                    'warnings' => $row->warnings,
                    'reconciliation_status' => $row->reconciliationStatus,
                    'row_key' => $row->rowKey,
                    'source_refs' => $row->sourceRefs,
                ]);
            }
        });

        return $this->ref($context, BudgetingPortfolioSnapshot::query()->findOrFail($id));
    }

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
        string $code,
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query, $code);

        return $code === self::HEALTH_CODE
            ? $this->materializeHealth($context, $query, $progress)
            : $this->materializeLiquidity($context, $query, $progress);
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
        if (! $record instanceof BudgetingPortfolioSnapshot
            || ! hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        $quality = self::qualityFromRecord($record);
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
        if ($sourceRefs === [] || ! array_is_list($sourceRefs)) {
            throw new InvalidArgumentException('portfolio_source_refs_invalid');
        }

        foreach ($sourceRefs as $sourceRef) {
            if (! $sourceRef instanceof ReportSourceRef) {
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

    private function liquidityQuality(array $rows, array $qualityGaps = []): ReportQuality
    {
        $duplicates = array_sum(array_map(
            static fn (PortfolioLiquidityRow $row): int => $row->duplicateSourceCount,
            $rows,
        ));

        $empty = $rows === [];

        $gapCount = count($qualityGaps);

        return new ReportQuality(
            ! $empty && $duplicates === 0 && $gapCount === 0 ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage((string) count($rows), (string) count($rows), count($rows) === 0 ? null : '1.00000000'),
            $empty
                ? [new ReportWarning('SOURCE_EMPTY', ReportWarningSeverity::CRITICAL, null, 0)]
                : ($gapCount === 0 ? [] : [new ReportWarning('OPENING_BALANCE_MISSING', ReportWarningSeverity::CRITICAL, 'currency', $gapCount)]),
            $duplicates + $gapCount,
            $empty ? ReportReconciliationStatus::NOT_APPLICABLE : ($duplicates === 0 && $gapCount === 0 ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH),
            $empty ? ['source_coverage'] : ($gapCount === 0 ? [] : ['opening_balance']),
            $empty ? ['owner_snapshot'] : array_values(array_filter([
                $duplicates === 0 ? null : 'duplicate_cash_flow',
                $gapCount === 0 ? null : 'missing_opening_balance',
            ])),
        );
    }

    public static function qualityFromRecord(BudgetingPortfolioSnapshot $record): ReportQuality
    {
        if ((string) $record->report_code === self::LIQUIDITY_CODE) {
            $totalsValue = $record->getAttribute('totals');
            $totals = is_array($totalsValue) ? $totalsValue : [];
            $quality = is_array($totals['quality'] ?? null) ? $totals['quality'] : [];
            $gaps = is_array($quality['gaps'] ?? null) ? $quality['gaps'] : [];
            $duplicates = (int) ($quality['duplicate_source_count'] ?? 0);
            $count = (int) $record->row_count;
            $gapCount = count($gaps);
            $empty = $count === 0;

            return new ReportQuality(
                ReportQualityStatus::from((string) $record->quality_status),
                new ReportCoverage((string) $count, (string) $count, $empty ? null : '1.00000000'),
                $empty
                    ? [new ReportWarning('SOURCE_EMPTY', ReportWarningSeverity::CRITICAL, null, 0)]
                    : array_values(array_filter([
                        $gapCount === 0 ? null : new ReportWarning('OPENING_BALANCE_MISSING', ReportWarningSeverity::CRITICAL, 'currency', $gapCount),
                        $duplicates === 0 ? null : new ReportWarning('DUPLICATE_CASH_FLOW', ReportWarningSeverity::CRITICAL, null, $duplicates),
                    ])),
                $gapCount + $duplicates,
                $empty ? ReportReconciliationStatus::NOT_APPLICABLE : ($gapCount === 0 && $duplicates === 0 ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH),
                $empty ? ['source_coverage'] : array_values(array_filter([
                    $gapCount === 0 ? null : 'opening_balance',
                    $duplicates === 0 ? null : 'cash_flow_key',
                ])),
                $empty ? ['owner_snapshot'] : array_values(array_filter([
                    $gapCount === 0 ? null : 'missing_opening_balance',
                    $duplicates === 0 ? null : 'duplicate_cash_flow',
                ])),
            );
        }

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
        $closingByStream = [];
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
            $stream = $row->projectId.':'.$row->scenario;
            $current = $closingByStream[$row->currency][$stream] ?? null;
            if (! is_array($current) || $row->forecastDate >= $current['date']) {
                $closingByStream[$row->currency][$stream] = [
                    'date' => $row->forecastDate,
                    'closing' => $row->closing,
                ];
            }
        }
        foreach ($closingByStream as $currency => $streams) {
            foreach ($streams as $streamKey => $stream) {
                $totals[$currency]['closing'] = PortfolioDecimal::add(
                    $totals[$currency]['closing'],
                    $stream['closing'],
                );
                [$projectId, $scenario] = explode(':', $streamKey, 2);
                $totals[$currency]['by_stream'][$streamKey] = [
                    'project_id' => (int) $projectId,
                    'scenario' => $scenario,
                    'forecast_date' => $stream['date'],
                    'closing' => $stream['closing'],
                ];
            }
            ksort($totals[$currency]['by_stream'], SORT_STRING);
        }
        ksort($totals, SORT_STRING);

        return $totals;
    }

    private function materializeHealth(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $filters = $this->filters($context, $query, false);
        $projects = $this->projects($context, $query);
        $input = $this->reportInput($filters);
        $margin = $this->marginReports->report([
            ...$input,
            'group_by' => [ProjectMarginReportFilters::GROUP_PROJECT, ProjectMarginReportFilters::GROUP_CURRENCY],
        ]);
        $progress->advance(20);
        $wip = $this->wipReports->report([
            ...$input,
            'as_of_date' => $query->asOf->format('Y-m-d'),
            'group_by' => [WipForecastReportFilters::GROUP_PROJECT, WipForecastReportFilters::GROUP_CURRENCY],
        ]);
        $progress->advance(40);
        $planFact = $this->planFactReports->report([
            ...$input,
            'group_by' => ['project', 'currency'],
        ]);
        $calendar = $this->scopeCalendar(
            $this->calendarSources->collect($filters->calendarFilters(), $query->asOf),
            $context,
            $query,
        );
        $provenance = $this->healthProvenance($context, $query);
        $margin = $this->attachHealthSourceRefs($margin, $provenance, 'margin');
        $wip = $this->attachHealthSourceRefs($wip, $provenance, 'wip');
        $planFact['rows'] = $this->attachHealthRowRefs(
            is_array($planFact['rows'] ?? null) ? $planFact['rows'] : [],
            $provenance,
            'plan_fact',
        );
        $progress->advance(60);

        $this->assertCriticalSourcesFresh($margin, $wip);
        $projection = $this->portfolioAggregator->buildResult(
            $filters,
            $projects,
            $margin,
            $wip,
            is_array($planFact['rows'] ?? null) ? $planFact['rows'] : [],
            $calendar,
            $query->asOf->format(DateTimeInterface::ATOM),
            max(1, count($projects)),
        );
        if ($projection->rows === []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        $payloads = [
            'project_margin' => $margin,
            'wip_forecast' => $wip,
            'plan_fact' => $planFact,
            'payment_calendar' => array_map(
                static fn (PaymentCalendarItem $item): array => $item->toArray(),
                $calendar,
            ),
        ];
        $sourceRefs = $this->sourceRefs($payloads);
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($payloads)));
        $snapshot = $this->persistHealth(
            $context,
            $query,
            $projection,
            $sourceHash,
            $this->watermarks($payloads),
            $sourceRefs,
        );
        $progress->advance(100);

        return $snapshot;
    }

    private function materializeLiquidity(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $filters = $this->filters($context, $query, true);
        $versionedSource = app(PortfolioLiquidityAsOfSource::class)->read(
            $context->scope->organizationId,
            $filters->calendarFilters(),
            $query->asOf,
        );
        $calendar = $this->scopeCalendar(
            $versionedSource['calendar'],
            $context,
            $query,
        );
        $currencies = $this->currencies($query, $calendar);
        $balances = $versionedSource['balances'];
        if ($currencies === []) {
            $currencies = array_keys($balances);
            sort($currencies, SORT_STRING);
        }
        $progress->advance(25);

        $projectId = $filters->projectId ?? 0;
        $projectName = $projectId === 0
            ? 'Портфель'
            : (string) (Project::query()->whereKey($projectId)->value('name') ?? '');
        if ($projectId > 0 && $projectName === '') {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        $scenarios = $this->scenarios($query);
        $forecastItems = array_map(
            static fn (PaymentCalendarItem $item) => $item->toCashGapForecastItem(),
            $calendar,
        );
        $rows = [];
        $gaps = [];
        $sourcePayload = [
            'payment_calendar' => array_map(
                static fn (PaymentCalendarItem $item): array => $item->toArray(),
                $calendar,
            ),
            'opening_balances' => [],
            'source_versions' => $versionedSource['versions'],
        ];

        foreach ($currencies as $currency) {
            $balance = $balances[$currency] ?? null;
            if ($balance === null) {
                $gaps[] = ['code' => 'opening_balance_missing', 'currency' => $currency];

                continue;
            }
            $sourcePayload['opening_balances'][$currency] = [
                'id' => $balance->id,
                'balance_date' => $balance->balanceDate,
                'amount' => $this->decimal($balance->amount),
                'approved_at' => $balance->approvedAt,
            ];

            foreach ($scenarios as $scenario) {
                $forecast = $this->cashGapForecasts->forecast(
                    new CashGapForecastContext(
                        $filters->periodStart,
                        $filters->periodEnd,
                        $balance->amount,
                        $scenario,
                        $filters->cashGapFilters($currency),
                    ),
                    $forecastItems,
                );
                foreach ($forecast->days as $day) {
                    $refs = $this->liquidityDaySourceRefs($day->drivers);
                    $refs[] = ['type' => 'opening_balance', 'id' => $balance->id];
                    if ($projectId > 0) {
                        $refs[] = ['type' => 'project', 'id' => $projectId];
                    }
                    $outflow = PortfolioDecimal::add(
                        $this->decimal($day->outflows),
                        $this->decimal($day->reservedOutflows),
                        $this->decimal($day->overdueOutflows),
                    );
                    $rows[] = new PortfolioLiquidityRow(
                        $day->date,
                        $projectId,
                        $projectName,
                        $currency,
                        $scenario,
                        $this->decimal($day->openingBalance),
                        $this->decimal($day->inflows),
                        $outflow,
                        0,
                        'complete',
                        $this->uniqueRefs($refs),
                    );
                }
            }
        }
        if ($rows === []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($sourcePayload)));
        $sourceRefs = $this->sourceRefs($sourcePayload);
        $snapshot = $this->persistLiquidity(
            $context,
            $query,
            $rows,
            $sourceHash,
            $this->watermarks($sourcePayload),
            $sourceRefs,
            ReportFreshnessStatus::FRESH,
            $gaps,
        );
        $progress->advance(100);

        return $snapshot;
    }

    private function filters(
        ReportExecutionContext $context,
        ReportQuery $query,
        bool $liquidity,
    ): CfoCommandCenterFilters {
        $values = $query->filters->values;
        $projectIds = $this->effectiveProjectIds($context, $query);
        $periodStart = $this->dateFilter(
            $values[$liquidity ? 'horizon_from' : 'period_from'] ?? null,
            $query->asOf->format('Y-m-d'),
        );
        $defaultEnd = $liquidity
            ? $query->asOf->modify('+30 days')->format('Y-m-d')
            : $query->asOf->format('Y-m-d');
        $periodEnd = $this->dateFilter(
            $values[$liquidity ? 'horizon_to' : 'period_to'] ?? null,
            $defaultEnd,
        );
        if ($periodEnd < $periodStart) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        return new CfoCommandCenterFilters(
            organizationId: $context->scope->organizationId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            projectId: count($projectIds) === 1 ? $projectIds[0] : null,
            projectManagerUserId: $this->firstPositiveInt($values['manager_ids'] ?? null),
            projectStatus: $this->firstString($values['project_statuses'] ?? null),
            responsibilityCenterId: $this->firstPositiveInt($values['responsibility_center_ids'] ?? null),
            counterpartyId: $this->firstPositiveInt($values['counterparty_ids'] ?? null),
            currency: $this->singleCurrency($values['currencies'] ?? null),
            itemLimit: 50,
        );
    }

    private function projects(ReportExecutionContext $context, ReportQuery $query): array
    {
        $ids = $this->effectiveProjectIds($context, $query);

        return Project::query()
            ->accessibleByOrganization($context->scope->organizationId)
            ->when($ids !== [], static fn (Builder $builder): Builder => $builder->whereIn('id', $ids))
            ->orderBy('id')
            ->get(['id', 'name', 'status', 'additional_info'])
            ->mapWithKeys(static fn (Project $project): array => [
                (int) $project->getKey() => [
                    'id' => (int) $project->getKey(),
                    'name' => (string) $project->name,
                    'status' => (string) $project->status,
                    'project_type' => is_array($project->additional_info)
                        ? ($project->additional_info['project_type'] ?? null)
                        : null,
                ],
            ])
            ->all();
    }

    private function scopeCalendar(
        array $calendar,
        ReportExecutionContext $context,
        ReportQuery $query,
    ): array {
        $projectIds = $this->effectiveProjectIds($context, $query);
        if ($projectIds === []) {
            return $calendar;
        }
        $allowed = array_fill_keys($projectIds, true);

        return array_values(array_filter(
            $calendar,
            static fn (mixed $item): bool => $item instanceof PaymentCalendarItem
                && $item->projectId !== null
                && isset($allowed[$item->projectId]),
        ));
    }

    private function effectiveProjectIds(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): array {
        $filterIds = $this->positiveIds($query->filters->values['project_ids'] ?? []);
        $scopeIds = $context->scope->projectIds;

        if ($scopeIds === []) {
            return $filterIds;
        }
        if ($filterIds === []) {
            return $scopeIds;
        }

        $effectiveIds = array_values(array_intersect($scopeIds, $filterIds));
        if (count($effectiveIds) !== count($filterIds)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        return $effectiveIds;
    }

    private function reportInput(CfoCommandCenterFilters $filters): array
    {
        return array_filter([
            'organization_id' => $filters->organizationId,
            'period_start' => $filters->periodStart,
            'period_end' => $filters->periodEnd,
            'project_id' => $filters->projectId,
            'responsibility_center_id' => $filters->responsibilityCenterId,
            'counterparty_id' => $filters->counterpartyId,
            'currency' => $filters->currency,
            '_skip_data_mart_meta' => true,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function assertCriticalSourcesFresh(array $margin, array $wip): void
    {
        $statuses = [
            $margin['summary']['quality_status'] ?? null,
            $margin['freshness']['status'] ?? null,
            $wip['summary']['quality_status'] ?? null,
            $wip['freshness']['status'] ?? null,
        ];
        foreach ($statuses as $status) {
            if (is_string($status) && in_array($status, ['stale', 'unavailable', 'invalid'], true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
        }
        if (($margin['available'] ?? true) === false || ($wip['available'] ?? true) === false) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        foreach ([
            $margin['sources_coverage'] ?? null,
            $wip['source_coverage'] ?? null,
        ] as $coverage) {
            if (! is_array($coverage) || $coverage === []) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            foreach ($coverage as $source) {
                if (! is_array($source)
                    || ($source['available'] ?? false) !== true
                    || ! array_key_exists('included_source_rows', $source)) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
                }
            }
        }
    }

    private function sourceRefs(array $payloads): array
    {
        $refs = [];
        foreach ($payloads as $source => $payload) {
            $normalizedSource = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $source));
            if (! is_string($normalizedSource) || $normalizedSource === '') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }
            $arrayPayload = is_array($payload) ? $payload : [];
            $hash = new Sha256Hash(hash('sha256', CanonicalJson::encode($arrayPayload)));
            $refs[] = new ReportSourceRef(
                $normalizedSource,
                $normalizedSource,
                'snapshot_'.substr($hash->value, 0, 24),
                $normalizedSource.'_v1',
                'watermark_'.substr($hash->value, 0, 24),
                $this->payloadRowCount($arrayPayload),
                $hash,
            );
        }

        return $refs;
    }

    private function watermarks(array $payloads): array
    {
        $watermarks = [];
        foreach ($payloads as $source => $payload) {
            $arrayPayload = is_array($payload) ? $payload : [];
            $watermarks[(string) $source] = hash('sha256', CanonicalJson::encode($arrayPayload));
        }
        ksort($watermarks, SORT_STRING);

        return $watermarks;
    }

    private function payloadRowCount(array $payload): int
    {
        foreach (['rows', 'items', 'daily'] as $field) {
            if (is_array($payload[$field] ?? null)) {
                return count($payload[$field]);
            }
        }

        return count($payload);
    }

    private function currencies(ReportQuery $query, array $calendar): array
    {
        $currencies = [];
        foreach ($this->strings($query->filters->values['currencies'] ?? []) as $currency) {
            $currency = mb_strtoupper($currency);
            if (preg_match('/^[A-Z]{3}$/D', $currency) === 1) {
                $currencies[$currency] = true;
            }
        }
        foreach ($calendar as $item) {
            if ($item instanceof PaymentCalendarItem && preg_match('/^[A-Z]{3}$/D', $item->currency) === 1) {
                $currencies[mb_strtoupper($item->currency)] = true;
            }
        }
        $result = array_keys($currencies);
        sort($result, SORT_STRING);

        return $result;
    }

    private function scenarios(ReportQuery $query): array
    {
        $allowed = [
            CashGapForecastContext::SCENARIO_OPTIMISTIC,
            CashGapForecastContext::SCENARIO_BASE,
            CashGapForecastContext::SCENARIO_PESSIMISTIC,
            CashGapForecastContext::SCENARIO_STRESS,
            CashGapForecastContext::SCENARIO_CUSTOM,
        ];
        $scenarios = $this->strings($query->filters->values['scenarios'] ?? []);
        if ($scenarios === []) {
            return [CashGapForecastContext::SCENARIO_BASE];
        }
        foreach ($scenarios as $scenario) {
            if (! in_array($scenario, $allowed, true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
            }
        }

        return array_values(array_unique($scenarios));
    }

    private function liquidityDaySourceRefs(array $drivers): array
    {
        $types = [
            'payment_document' => 'payment_document',
            'payment_schedule' => 'payment_schedule',
            'payment_transaction' => 'payment_transaction',
            'transaction' => 'payment_transaction',
            'budget_limit_reservation' => 'budget_reservation',
            'budget_amount' => 'budget_plan',
        ];
        $refs = [];
        foreach ($drivers as $driver) {
            $source = is_array($driver['source'] ?? null) ? $driver['source'] : [];
            $type = is_string($source['type'] ?? null) ? ($types[$source['type']] ?? null) : null;
            $id = $source['id'] ?? null;
            if ($type !== null && (is_int($id) || ctype_digit((string) $id))) {
                $refs[] = ['type' => $type, 'id' => (int) $id];
            }
        }

        return $refs;
    }

    private function uniqueRefs(array $refs): array
    {
        $unique = [];
        foreach ($refs as $ref) {
            if (! is_array($ref) || ! is_string($ref['type'] ?? null) || ! isset($ref['id'])) {
                continue;
            }
            $unique[$ref['type'].':'.(string) $ref['id']] = [
                'type' => $ref['type'],
                'id' => $ref['id'],
            ];
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    private function decimal(float|int|string $value): string
    {
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        }

        return PortfolioDecimal::money($value);
    }

    private function positiveIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if ((is_int($id) || ctype_digit((string) $id)) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));
    }

    private function firstPositiveInt(mixed $value): ?int
    {
        return $this->positiveIds(is_array($value) ? $value : [])[0] ?? null;
    }

    private function firstString(mixed $value): ?string
    {
        return $this->strings(is_array($value) ? $value : [])[0] ?? null;
    }

    private function singleCurrency(mixed $value): ?string
    {
        $currencies = $this->strings(is_array($value) ? $value : []);

        return count($currencies) === 1 ? mb_strtoupper($currencies[0]) : null;
    }

    private function dateFilter(mixed $value, string $default): string
    {
        $candidate = is_string($value) ? $value : $this->firstString($value);
        if ($candidate === null) {
            return $default;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $candidate) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        return $candidate;
    }

    private function rowSchema(string $code): array
    {
        $ids = $code === self::HEALTH_CODE
            ? ['project', 'currency', 'revenue', 'cost', 'margin', 'margin_percent', 'wip', 'ftc', 'eac', 'ctc', 'risk']
            : ['date', 'project', 'scenario', 'currency', 'opening', 'inflow', 'outflow', 'closing', 'gap', 'quality'];

        return array_map(static fn (string $id): array => ['id' => $id], $ids);
    }

    private function healthProvenance(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): array {
        $organizationId = $context->scope->organizationId;
        $asOf = $query->asOf;
        $refs = [];

        foreach (ContractPerformanceAct::query()
            ->where('is_approved', true)
            ->whereDate('approval_date', '<=', $asOf)
            ->whereHas('contract', static fn (Builder $builder): Builder => $builder->where('organization_id', $organizationId))
            ->get(['id', 'project_id']) as $act) {
            $this->addHealthRef($refs, (int) $act->project_id, 'margin', 'approved_act', (int) $act->getKey());
        }
        foreach (PaymentTransaction::query()
            ->where('organization_id', $organizationId)
            ->where('status', PaymentTransactionStatus::COMPLETED)
            ->where('created_at', '<=', $asOf)
            ->get(['id', 'project_id']) as $transaction) {
            $this->addHealthRef(
                $refs,
                (int) $transaction->project_id,
                'margin',
                'payment_transaction',
                (int) $transaction->getKey(),
            );
        }
        foreach (WipForecastLine::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $asOf)
            ->get(['id', 'project_id']) as $line) {
            $this->addHealthRef($refs, (int) $line->project_id, 'wip', 'earned_value', (string) $line->getKey());
            $this->addHealthRef($refs, (int) $line->project_id, 'wip', 'actual_cost', (string) $line->getKey());
        }
        foreach (BudgetLine::query()
            ->whereHas('version', static fn (Builder $builder): Builder => $builder
                ->where('organization_id', $organizationId)
                ->where('created_at', '<=', $asOf))
            ->get(['id', 'project_id']) as $line) {
            $this->addHealthRef($refs, (int) $line->project_id, 'plan_fact', 'budget_line', (string) $line->getKey());
        }

        return $refs;
    }

    private function addHealthRef(
        array &$refs,
        int $projectId,
        string $group,
        string $type,
        int|string $id,
    ): void {
        if ($projectId < 1 || (string) $id === '') {
            return;
        }
        $refs[$projectId][$group][$type.':'.(string) $id] = ['type' => $type, 'id' => $id];
    }

    private function attachHealthSourceRefs(array $report, array $provenance, string $group): array
    {
        $report['rows'] = $this->attachHealthRowRefs(
            is_array($report['rows'] ?? null) ? $report['rows'] : [],
            $provenance,
            $group,
        );

        return $report;
    }

    private function attachHealthRowRefs(array $rows, array $provenance, string $group): array
    {
        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }
            $project = is_array($row['project'] ?? null) ? $row['project'] : [];
            $projectId = (int) ($project['id'] ?? $row['project_id'] ?? 0);
            $row['source_refs'] = $this->uniqueRefs([
                ...(is_array($row['source_refs'] ?? null) ? $row['source_refs'] : []),
                ...array_values($provenance[$projectId][$group] ?? []),
            ]);
        }
        unset($row);

        return $rows;
    }
}

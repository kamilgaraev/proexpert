<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursorKeyset;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\Contracts\PlanFactSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\Contracts\ProjectMarginSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceWatermark;
use App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportSourceCloseService;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotWriter;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotMaterializer;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotWriter;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class BudgetingReportSourceSnapshotAdapterTest extends TestCase
{
    #[DataProvider('adapters')]
    public function test_materializes_approved_close_and_replays_only_the_persisted_snapshot(
        string $code,
        string $sourceKind,
        string $drillColumn,
        object $adapter,
        object $source,
        InMemoryReportSourceSnapshotStore $store,
    ): void {
        $context = (new ReportExecutionContextBuilder)->build();
        $query = $this->query($code, $context->scope);
        $sort = new ReportWindowSort('row_key', ReportSortDirection::ASC);

        $progress = new ReportProgress(0);
        $snapshot = $adapter->materialize($context, $query, $progress);
        $liveCalls = $source->calls;
        $result = $adapter->result($context, $snapshot);
        $first = $adapter->page($context, $snapshot, $sort, null, 1);
        $second = $adapter->page(
            $context,
            $snapshot,
            $sort,
            $this->cursor($snapshot, $query, $sort, $first->rows[0]['row_key']),
            1,
        );
        $drill = $adapter->drillDown(
            $context,
            $snapshot,
            new ReportDrillDownInput(new ReportDrillDownCell($first->rows[0]['row_key'], $drillColumn), null, 100),
        );
        $cursorRows = iterator_to_array($adapter->cursor($context, $snapshot, $sort, 1), false);

        self::assertSame($sourceKind, $snapshot->kind);
        self::assertSame($store->headerValue?->id, $snapshot->id);
        self::assertSame($store->headerValue?->sourceHash->value, $snapshot->sourceHash->value);
        self::assertSame('01JZZZZZZZZZZZZZZZZZZZZZZZ', $snapshot->watermarks['close_id']);
        self::assertSame(str_repeat('a', 64), $snapshot->watermarks['content_hash']);
        self::assertSame('actuals:1', $snapshot->watermarks['source_watermarks'][0]['watermark']);
        self::assertSame(2, $result->metadata->rowCount);
        self::assertSame('v1_0_0', $result->provenance->sourceRefs[0]->schemaVersion);
        self::assertSame(100, $progress->percent());
        self::assertSame(2, count($cursorRows));
        self::assertTrue($first->hasMore);
        self::assertFalse($second->hasMore);
        self::assertNotSame($first->rows[0]['row_key'], $second->rows[0]['row_key']);
        self::assertSame($first->rows[0]['row_key'], $cursorRows[0]['row_key']);
        self::assertSame($snapshot->id, $cursorRows[0]['snapshot_id']);
        self::assertSame($snapshot->sourceHash->value, $cursorRows[0]['source_hash']);
        self::assertCount(1, $drill->rows);
        self::assertSame($liveCalls, $source->calls);
        self::assertStringNotContainsString('Sensitive', json_encode([$first, $drill, $cursorRows], JSON_THROW_ON_ERROR));
    }

    #[DataProvider('adapters')]
    public function test_rejects_snapshot_source_hash_or_scope_drift(
        string $code,
        string $sourceKind,
        string $drillColumn,
        object $adapter,
        object $source,
        InMemoryReportSourceSnapshotStore $store,
    ): void {
        $context = (new ReportExecutionContextBuilder)->build();
        $query = $this->query($code, $context->scope);
        $snapshot = $adapter->materialize($context, $query, new ReportProgress(0));
        $drifted = new ReportSnapshotRef(
            $snapshot->kind,
            $snapshot->id,
            $snapshot->scope,
            $snapshot->definitionHash,
            $snapshot->formulaVersion,
            new Sha256Hash(str_repeat('b', 64)),
            $snapshot->generatedAt,
            $snapshot->staleAt,
            $snapshot->watermarks,
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage('REPORT_INTERNAL_ERROR');

        $adapter->result($context, $drifted);
    }

    #[DataProvider('adapters')]
    public function test_cursor_rejects_snapshot_source_hash_drift(
        string $code,
        string $sourceKind,
        string $drillColumn,
        object $adapter,
        object $source,
        InMemoryReportSourceSnapshotStore $store,
    ): void {
        $context = (new ReportExecutionContextBuilder)->build();
        $query = $this->query($code, $context->scope);
        $snapshot = $adapter->materialize($context, $query, new ReportProgress(0));
        $drifted = new ReportSnapshotRef(
            $snapshot->kind,
            $snapshot->id,
            $snapshot->scope,
            $snapshot->definitionHash,
            $snapshot->formulaVersion,
            new Sha256Hash(str_repeat('b', 64)),
            $snapshot->generatedAt,
            $snapshot->staleAt,
            $snapshot->watermarks,
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage('REPORT_INTERNAL_ERROR');

        iterator_to_array($adapter->cursor($context, $drifted, new ReportWindowSort('row_key', ReportSortDirection::ASC), 1));
    }

    #[DataProvider('adapters')]
    public function test_rejects_source_schema_version_mismatch(
        string $code,
        string $sourceKind,
        string $drillColumn,
        object $adapter,
        object $source,
        InMemoryReportSourceSnapshotStore $store,
    ): void {
        $context = (new ReportExecutionContextBuilder)->build();

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage('REPORT_REQUEST_INVALID');

        $adapter->materialize($context, $this->query($code, $context->scope, sourceSchemaVersion: '2.0.0'), new ReportProgress(0));
    }

    #[DataProvider('adapters')]
    public function test_rejects_formula_version_mismatch_with_approved_close(
        string $code,
        string $sourceKind,
        string $drillColumn,
        object $adapter,
        object $source,
        InMemoryReportSourceSnapshotStore $store,
    ): void {
        $context = (new ReportExecutionContextBuilder)->build();

        try {
            $adapter->materialize($context, $this->query($code, $context->scope, formulaVersion: 'margin-v2'), new ReportProgress(0));
            self::fail('Formula mismatch must be rejected before snapshot persistence.');
        } catch (ReportContractException $exception) {
            self::assertSame('REPORT_REQUEST_INVALID', $exception->getMessage());
        }

        self::assertSame(0, $source->calls);
        self::assertNull($store->headerValue);
    }

    public static function adapters(): array
    {
        return [
            'G09 project margin' => (static function (): array {
                $store = new InMemoryReportSourceSnapshotStore();
                $source = new ProjectMarginSnapshotSource();
                $closeService = self::closeService();
                $adapter = new ProjectMarginReportSourceSnapshotAdapter(
                    new ProjectMarginSourceSnapshotWriter(
                        $source,
                        new ProjectMarginSourceSnapshotMaterializer(),
                        $store,
                        $closeService,
                    ),
                    $closeService,
                    $store,
                );

                return ['project_margin', 'budgeting.project_margin', 'attributions', $adapter, $source, $store];
            })(),
            'G10 plan fact' => (static function (): array {
                $store = new InMemoryReportSourceSnapshotStore();
                $source = new PlanFactSnapshotSource();
                $closeService = self::closeService();
                $adapter = new PlanFactReportSourceSnapshotAdapter(
                    new PlanFactSourceSnapshotWriter(
                        $source,
                        new PlanFactSourceSnapshotMaterializer(),
                        $store,
                        $closeService,
                    ),
                    $closeService,
                    $store,
                );

                return ['budget_plan_fact', 'budgeting.plan_fact', 'sources', $adapter, $source, $store];
            })(),
        ];
    }

    private function query(
        string $code,
        ReportScope $scope,
        string $formulaVersion = 'margin-v1',
        string $sourceSchemaVersion = '1.0.0',
    ): ReportQuery
    {
        return new ReportQuery(
            (new ReportDefinitionBuilder)
                ->code($code)
                ->formulaVersion($formulaVersion)
                ->sourceSchemaVersion($sourceSchemaVersion)
                ->columns([['id' => 'row_key']])
                ->sorts([['id' => 'row_key']])
                ->payload(),
            $scope,
            new ReportFilterSet([
                'organization_id' => 1,
                'period_start' => '2026-01-01',
                'period_end' => '2026-01-31',
                'scenario_uuid' => 'scenario-1',
                'budget_version_uuid' => 'budget-1',
                'close_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            ]),
            [],
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            'ru',
        );
    }

    private function cursor(ReportSnapshotRef $snapshot, ReportQuery $query, ReportWindowSort $sort, string $rowKey): ReportCursor
    {
        return new ReportCursor(
            'cursor-token',
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            $query->queryHash,
            $snapshot->sourceHash,
            $sort,
            new ReportCursorKeyset($rowKey, $rowKey),
            new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        );
    }

    private static function closeService(): BudgetingReportSourceCloseService
    {
        $close = new BudgetingReportSourceClose(
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            new BudgetingReportSourceCloseIdentity(1, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1'),
            [new BudgetingReportSourceWatermark('actuals', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'actuals:1', 'actuals-v1')],
            'margin-v1',
            ['actuals' => ['version' => 'actuals:1']],
            str_repeat('a', 64),
            1,
            new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
            BudgetingReportSourceCloseStatus::APPROVED,
            null,
        );

        return new BudgetingReportSourceCloseService(new class($close) implements BudgetingReportSourceCloseStore {
            public function __construct(private readonly BudgetingReportSourceClose $close) {}

            public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
            {
                throw new LogicException();
            }

            public function find(string $closeId): ?BudgetingReportSourceClose
            {
                return $closeId === $this->close->closeId ? $this->close : null;
            }
        });
    }
}

final class InMemoryReportSourceSnapshotStore implements ReportSourceSnapshotStore
{
    public ?ReportSourceSnapshotHeader $headerValue = null;

    /** @var list<\App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow> */
    private array $rows = [];

    /** @var list<\App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow> */
    private array $drillRows = [];

    public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
    {
        ReportSourceSnapshotIntegrity::assertWrite($snapshot);
        $header = $snapshot->header;
        $this->headerValue = new ReportSourceSnapshotHeader(
            $header->id, $header->sourceKind, $header->reportCode, $header->schemaVersion, $header->scope,
            $header->queryHash, $header->asOf, $header->sourceHash, $header->watermarks, $header->generatedAt,
            $header->staleAt, \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus::READY,
            $header->rowCount, $header->drillRowCount, $header->snapshotHash, $header->generatedAt, null,
        );
        $this->rows = $snapshot->rows;
        $this->drillRows = $snapshot->drillRows;

        return $this->headerValue;
    }

    public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
    {
        $header = $this->headerValue ?? throw new LogicException();
        ReportSourceSnapshotIntegrity::assertReadable($header, $request);

        return $header;
    }

    public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
    {
        $header = $this->header($request);
        $after = $cursor?->afterOrdinal ?? 0;
        if ($cursor !== null && $cursor->snapshotId !== $header->id) {
            throw new LogicException();
        }
        $rows = array_values(array_filter($this->rows, static fn ($row): bool => $row->ordinal > $after));
        $items = array_slice($rows, 0, $limit);
        $next = count($rows) > $limit ? new ReportSourceSnapshotCursor($header->id, $items[array_key_last($items)]->ordinal) : null;

        return new ReportSourceSnapshotPage($items, $next);
    }

    public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
    {
        $header = $this->header($request);
        $after = $cursor?->afterOrdinal ?? 0;
        $rows = array_values(array_filter($this->drillRows, static fn ($row): bool => $row->rowKey === $rowKey && $row->columnId === $columnId && $row->ordinal > $after));
        $items = array_slice($rows, 0, $limit);
        $next = count($rows) > $limit ? new ReportSourceSnapshotCursor($header->id, $items[array_key_last($items)]->ordinal) : null;

        return new ReportSourceSnapshotDrillPage($items, $next);
    }
}

final class ProjectMarginSnapshotSource implements ProjectMarginSourceSnapshotReport
{
    public int $calls = 0;

    public function reportForProjectScope(array $input, array $projectIds): array
    {
        $this->calls++;

        return ['filters' => $input, 'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'], 'rows' => [
            $this->row('first', 'a'), $this->row('second', 'b'),
        ]];
    }

    public function drillDownForProjectScope(array $input, array $projectIds): array
    {
        $this->calls++;

        return ['items' => [[
            'line_id' => 'line-'.$input['drill_down_key'], 'amount_without_vat' => 10.0,
            'attribution_rule' => 'rule', 'component' => 'cost', 'confirmation_status' => 'confirmed',
            'currency' => 'RUB', 'direction' => 'outflow', 'freshness_status' => 'fresh', 'management_amount' => 10.0,
            'period' => '2026-01', 'problem_flags' => [], 'quality_status' => 'complete',
            'recognition_date' => '2026-01-15', 'recognition_event' => 'invoice', 'reconciliation_status' => 'matched',
            'risk_flags' => [], 'source_type' => 'actuals', 'vat_amount' => 2.0, 'title' => 'Sensitive drill',
        ]], 'meta' => ['total' => 1]];
    }

    private function row(string $drillKey, string $article): array
    {
        $money = ['cost' => 2.0, 'gross_margin' => 8.0, 'margin_percent' => 80.0, 'revenue' => 10.0];

        return [
            'actual' => $money, 'currency' => 'RUB', 'drill_down_key' => $drillKey,
            'forecast' => $money, 'group' => ['article' => $article], 'plan' => $money,
            'problem_flags' => [], 'quality_status' => 'complete', 'risk_flags' => [], 'source_rows_count' => 1,
            'source_types' => ['actuals'], 'variance' => $money, 'project' => ['name' => 'Sensitive project'],
        ];
    }
}

final class PlanFactSnapshotSource implements PlanFactSourceSnapshotReport
{
    public int $calls = 0;

    public function reportForProjectScope(array $input, array $projectIds): array
    {
        $this->calls++;

        return ['filters' => $input, 'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'], 'sources_coverage' => [], 'rows' => [
            $this->row('first', 'a'), $this->row('second', 'b'),
        ]];
    }

    public function drillDownForProjectScope(array $input, array $projectIds): array
    {
        $this->calls++;

        return ['items' => [[
            'source_type' => 'actuals', 'source_id' => $input['drill_down_key'], 'date' => '2026-01-15',
            'amount' => 10.0, 'currency' => 'RUB', 'status' => 'completed', 'variance_contribution' => 1.0,
            'title' => 'Sensitive drill', 'route_hint' => ['secret' => 'value'],
        ]], 'meta' => ['total' => 1]];
    }

    private function row(string $drillKey, string $article): array
    {
        return [
            'actual_amount' => 8.0, 'committed_amount' => 1.0, 'currency' => 'RUB', 'drill_down_key' => $drillKey,
            'forecast_amount' => 9.0, 'group' => ['article' => $article], 'plan_amount' => 10.0,
            'risk_level' => 'low', 'variance_amount' => 2.0, 'variance_percent' => 20.0,
            'project' => ['name' => 'Sensitive project'],
        ];
    }
}

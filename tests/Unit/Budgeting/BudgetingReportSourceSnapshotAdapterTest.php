<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStreamingStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursorKeyset;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotStream;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
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
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportSourceCloseService;
use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotWriter;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotMaterializer;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotWriter;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class BudgetingReportSourceSnapshotAdapterTest extends TestCase
{
    #[DataProvider('adapters')]
    public function test_reuses_close_bound_ready_snapshot_before_reading_mutated_live_source(
        string $code,
        string $sourceKind,
        string $drillColumn,
        object $adapter,
        object $source,
        InMemoryReportSourceSnapshotStore $store,
    ): void {
        $context = (new ReportExecutionContextBuilder)->build();
        $query = $this->query($code, $context->scope);

        $snapshotA = $adapter->materialize($context, $query, new ReportProgress(0));
        $callsAfterA = $source->calls;
        $source->revision = 2;

        $snapshotAfterMutation = $adapter->materialize($context, $query, new ReportProgress(0));

        self::assertSame($snapshotA->id, $snapshotAfterMutation->id);
        self::assertSame($snapshotA->sourceHash->value, $snapshotAfterMutation->sourceHash->value);
        self::assertSame($callsAfterA, $source->calls);
        self::assertSame(1, $store->persistCalls);
    }

    #[DataProvider('adapters')]
    public function test_does_not_reuse_a_ready_snapshot_for_a_different_report_query_identity(
        string $code,
        string $sourceKind,
        string $drillColumn,
        object $adapter,
        object $source,
        InMemoryReportSourceSnapshotStore $store,
    ): void {
        $context = (new ReportExecutionContextBuilder)->build();
        $first = $adapter->materialize($context, $this->query($code, $context->scope), new ReportProgress(0));
        $second = $adapter->materialize(
            $context,
            $this->query($code, $context->scope, asOf: new DateTimeImmutable('2026-07-31T11:00:00+00:00')),
            new ReportProgress(0),
        );

        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, $store->persistCalls);
    }

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
        self::assertSame($store->headerValue?->materializedSourceHash->value, $snapshot->materializedSourceHash->value);
        self::assertSame($snapshot->canonicalReportHash->value, $result->provenance->sourceHash->value);
        self::assertNotSame($snapshot->canonicalReportHash->value, $snapshot->materializedSourceHash->value);
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

    public function test_plan_fact_release_binding_reads_rows_and_drill_from_one_sealed_snapshot(): void
    {
        $store = new InMemoryReportSourceSnapshotStore;
        $source = new PlanFactSnapshotSource;
        $closeService = self::closeService(BudgetPlanFactCandidateContract::FORMULA_VERSION);
        $adapter = new PlanFactReportSourceSnapshotAdapter(
            new PlanFactSourceSnapshotWriter($source, new PlanFactSourceSnapshotMaterializer, $store, $closeService),
            $closeService,
            $store,
        );
        $contract = new BudgetPlanFactCandidateContract;
        $definition = (new ReportDefinitionBuilder)
            ->code(BudgetPlanFactCandidateContract::CODE)
            ->contractVersion('1.0.0')
            ->formulaVersion(BudgetPlanFactCandidateContract::FORMULA_VERSION)
            ->sourceSchemaVersion(PlanFactSourceSnapshotMaterializer::SCHEMA_VERSION)
            ->filters($contract->filters())
            ->columns($contract->columns())
            ->sorts($contract->sorts())
            ->formats($contract->formats())
            ->permissionPolicy(new ReportPermissionPolicy(['budgeting.plan_fact.view'], ['budgeting.plan_fact.export'], [], []))
            ->publicationReadiness(ReportPublicationReadiness::DRAFT)
            ->payload();
        $binding = (new BudgetPlanFactReportBindingFactory($adapter, $contract))->create($definition);
        $context = (new ReportExecutionContextBuilder)->build();
        $query = new ReportQuery(
            $definition,
            $context->scope,
            new ReportFilterSet([
                'close_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
                'organization_id' => 1,
                'period_start' => '2026-01-01',
                'period_end' => '2026-01-31',
                'scenario_uuid' => 'scenario-1',
                'budget_version_uuid' => 'budget-1',
                'group_by' => \App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters::DEFAULT_GROUP_BY,
            ]),
            [],
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            'ru',
        );

        $snapshot = $binding->dataProvider->materialize($context, $query, new ReportProgress(0));
        $page = $binding->rowQuery->page($context, $snapshot, new ReportWindowSort('row_key', ReportSortDirection::ASC), null, 10);
        $drill = $binding->drillDownProvider->drillDown(
            $context,
            $snapshot,
            new ReportDrillDownInput(new ReportDrillDownCell($page->rows[0]['row_key'], PlanFactSourceSnapshotMaterializer::DRILL_COLUMN_ID), null, 10),
        );

        self::assertSame($adapter, $binding->dataProvider);
        self::assertSame($adapter, $binding->rowQuery);
        self::assertSame($adapter, $binding->drillDownProvider);
        self::assertSame($snapshot->id, $store->headerValue?->id);
        self::assertNotEmpty($page->rows);
        self::assertNotEmpty($drill->rows);
        self::assertSame([$snapshot->id], array_values(array_unique($store->readSnapshotIds)));

        $wrongScopeSnapshot = new ReportSnapshotRef(
            $snapshot->kind,
            $snapshot->id,
            new ReportScope(2, [2], [10, 20], [], new \DateTimeZone('UTC')),
            $snapshot->definitionHash,
            $snapshot->formulaVersion,
            $snapshot->sourceHash,
            $snapshot->generatedAt,
            $snapshot->staleAt,
            $snapshot->watermarks,
            $snapshot->classification,
            $snapshot->seal,
            $snapshot->materializedSourceHash,
        );
        $this->expectException(ReportContractException::class);
        $binding->rowQuery->page($context, $wrongScopeSnapshot, new ReportWindowSort('row_key', ReportSortDirection::ASC), null, 10);
    }

    public static function adapters(): array
    {
        return [
            'G09 project margin' => (static function (): array {
                $store = new InMemoryReportSourceSnapshotStore;
                $source = new ProjectMarginSnapshotSource;
                $closeService = self::closeService();
                $adapter = new ProjectMarginReportSourceSnapshotAdapter(
                    new ProjectMarginSourceSnapshotWriter(
                        $source,
                        new ProjectMarginSourceSnapshotMaterializer,
                        $store,
                        $closeService,
                    ),
                    $closeService,
                    $store,
                );

                return ['project_margin', 'budgeting.project_margin', 'attributions', $adapter, $source, $store];
            })(),
            'G10 plan fact' => (static function (): array {
                $store = new InMemoryReportSourceSnapshotStore;
                $source = new PlanFactSnapshotSource;
                $closeService = self::closeService();
                $adapter = new PlanFactReportSourceSnapshotAdapter(
                    new PlanFactSourceSnapshotWriter(
                        $source,
                        new PlanFactSourceSnapshotMaterializer,
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
        ?DateTimeImmutable $asOf = null,
    ): ReportQuery {
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
            $asOf ?? new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
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

    private static function closeService(string $formulaVersion = 'margin-v1'): BudgetingReportSourceCloseService
    {
        $close = new BudgetingReportSourceClose(
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            new BudgetingReportSourceCloseIdentity(1, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1'),
            [new BudgetingReportSourceWatermark('actuals', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'actuals:1', 'actuals-v1')],
            $formulaVersion,
            ['actuals' => ['version' => 'actuals:1']],
            str_repeat('a', 64),
            1,
            new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
            BudgetingReportSourceCloseStatus::APPROVED,
            null,
        );

        return new BudgetingReportSourceCloseService(new class($close) implements BudgetingReportSourceCloseStore
        {
            public function __construct(private readonly BudgetingReportSourceClose $close) {}

            public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
            {
                throw new LogicException;
            }

            public function find(string $closeId): ?BudgetingReportSourceClose
            {
                return $closeId === $this->close->closeId ? $this->close : null;
            }
        });
    }
}

final class InMemoryReportSourceSnapshotStore implements ReportSourceSnapshotStreamingStore
{
    public ?ReportSourceSnapshotHeader $headerValue = null;

    public int $persistCalls = 0;

    /** @var list<string> */
    public array $readSnapshotIds = [];

    /** @var list<\App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow> */
    private array $rows = [];

    /** @var list<\App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow> */
    private array $drillRows = [];

    private ?ReportSourceSnapshotIdentity $identityValue = null;

    public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
    {
        $this->persistCalls++;
        ReportSourceSnapshotIntegrity::assertWrite($snapshot);
        $header = $snapshot->header;
        $this->headerValue = new ReportSourceSnapshotHeader(
            $header->id, $header->sourceKind, $header->reportCode, $header->schemaVersion, $header->scope,
            $header->queryHash, $header->asOf, $header->sourceHash, $header->watermarks, $header->generatedAt,
            $header->staleAt, \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus::READY,
            $header->rowCount, $header->drillRowCount, $header->snapshotHash, $header->generatedAt, null,
            $header->reportQueryIdentity, $header->reportQueryHash,
        );
        $this->rows = $snapshot->rows;
        $this->drillRows = $snapshot->drillRows;

        return $this->headerValue;
    }

    public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader
    {
        if ($this->headerValue === null) {
            return null;
        }

        if ($this->identityValue === null
            || $this->identityValue->sourceVersion !== $identity->sourceVersion) {
            return null;
        }

        return $identity->matches($this->headerValue) ? $this->headerValue : null;
    }

    public function resolveReady(
        ReportSourceSnapshotIdentity $identity,
        ReportSourceSnapshotWrite $snapshot,
    ): ReportSourceSnapshotHeader {
        $ready = $this->findReady($identity);
        if ($ready === null) {
            $this->identityValue = $identity;

            return $this->persistReady($snapshot);
        }

        if (! hash_equals($ready->sourceHash->value, $snapshot->header->sourceHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT);
        }

        return $ready;
    }

    public function resolveReadyStreamed(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotStream $snapshot): ReportSourceSnapshotHeader
    {
        $ready = $this->findReady($identity);
        if ($ready !== null) {
            return $ready;
        }

        $drills = [];
        foreach ($snapshot->drillRows() as $row) {
            $drills[$row->rowKey][$row->columnId][] = $row;
        }
        $drillRows = [];
        foreach ($drills as $rowKey => $columns) {
            foreach ($columns as $columnId => $items) {
                usort($items, static fn ($left, $right): int => $left->sortKey <=> $right->sortKey);
                foreach ($items as $ordinal => $item) {
                    $drillRows[] = new ReportSourceSnapshotDrillRow(
                        $snapshot->id,
                        $rowKey,
                        $columnId,
                        $ordinal + 1,
                        $item->payload,
                        $item->payloadHash,
                    );
                }
            }
        }
        usort($drillRows, static fn (ReportSourceSnapshotDrillRow $left, ReportSourceSnapshotDrillRow $right): int => [$left->rowKey, $left->columnId, $left->ordinal] <=> [$right->rowKey, $right->columnId, $right->ordinal]);
        $sourceHash = ReportSourceSnapshotIntegrity::materializedSourceHash($snapshot->rows, $drillRows, $snapshot->watermarks);
        $pending = new Sha256Hash(str_repeat('a', 64));
        $writing = $snapshot->header($sourceHash, count($drillRows), $pending);
        $write = new ReportSourceSnapshotWrite(
            $snapshot->header($sourceHash, count($drillRows), ReportSourceSnapshotIntegrity::hashStream($writing, $snapshot->rows, $drillRows)),
            $snapshot->rows,
            $drillRows,
        );
        $this->identityValue = $identity;

        return $this->persistReady($write);
    }

    public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
    {
        $header = $this->headerValue ?? throw new LogicException;
        $this->readSnapshotIds[] = $request->snapshotId;
        ReportSourceSnapshotIntegrity::assertReadable($header, $request);

        return $header;
    }

    public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
    {
        $header = $this->header($request);
        $after = $cursor?->afterOrdinal ?? 0;
        if ($cursor !== null && $cursor->snapshotId !== $header->id) {
            throw new LogicException;
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

    public int $revision = 1;

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
        $money = [
            'cost' => 2.0 * $this->revision,
            'gross_margin' => 8.0 * $this->revision,
            'margin_percent' => 80.0,
            'revenue' => 10.0 * $this->revision,
        ];

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

    public int $revision = 1;

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
            'actual_amount' => 8.0 * $this->revision,
            'committed_amount' => 1.0 * $this->revision,
            'currency' => 'RUB',
            'drill_down_key' => $drillKey,
            'forecast_amount' => 9.0 * $this->revision,
            'group' => ['article' => $article],
            'plan_amount' => 10.0 * $this->revision,
            'risk_level' => 'low',
            'variance_amount' => 2.0 * $this->revision,
            'variance_percent' => 20.0,
            'project' => ['name' => 'Sensitive project'],
        ];
    }
}

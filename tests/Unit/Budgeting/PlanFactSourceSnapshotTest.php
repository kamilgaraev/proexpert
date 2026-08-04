<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStreamingStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
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
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\Contracts\PlanFactSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceWatermark;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactSourceSnapshotRequest;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportSourceCloseService;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportService;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotWriter;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlanFactSourceSnapshotTest extends TestCase
{
    public function test_materializes_stable_redacted_rows_hashes_and_drill_references(): void
    {
        $forward = $this->materialize($this->report());
        $reversedReport = $this->report();
        $reversedReport['rows'] = array_reverse($reversedReport['rows']);
        $backward = $this->materialize($reversedReport);

        self::assertCount(2, $forward->rows);
        self::assertSame([1, 2], array_map(static fn ($row): int => $row->ordinal, $forward->rows));
        self::assertMatchesRegularExpression('/^plan_fact:[a-f0-9]{64}$/', $forward->rows[0]->rowKey);
        self::assertSame($forward->header->sourceHash->value, $backward->header->sourceHash->value);
        self::assertSame($forward->header->snapshotHash->value, $backward->header->snapshotHash->value);
        self::assertSame(2, $forward->header->drillRowCount);
        self::assertSame($this->close()->closeId, $forward->header->watermarks['close_id']);
        self::assertSame('margin-v1', $forward->header->watermarks['formula_version']);
        self::assertSame('actuals-v1', $forward->header->watermarks['source_watermarks'][0]['source_schema_version']);
        self::assertSame('RUB', $forward->header->watermarks['result_totals_by_currency'][0]['currency']);
        self::assertSame('300', $forward->header->watermarks['result_totals_by_currency'][0]['plan_amount']);
        self::assertSame('sources', $forward->rows[0]->payload['drill']['column_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $forward->rows[0]->payload['drill']['key']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $forward->drillRows[0]->payload['source_ref']);
        self::assertArrayNotHasKey('project', $forward->rows[0]->payload);
        self::assertArrayNotHasKey('budget_article', $forward->rows[0]->payload);
        self::assertArrayNotHasKey('counterparty', $forward->rows[0]->payload);
        self::assertArrayNotHasKey('source_id', $forward->drillRows[0]->payload);
        self::assertArrayNotHasKey('number', $forward->drillRows[0]->payload);
        self::assertArrayNotHasKey('title', $forward->drillRows[0]->payload);
        self::assertArrayNotHasKey('route_hint', $forward->drillRows[0]->payload);
        self::assertStringNotContainsString('Sensitive project', json_encode($forward, JSON_THROW_ON_ERROR));
    }

    public function test_writer_uses_only_scoped_real_service_contract_and_persists_materialized_snapshot(): void
    {
        $report = new class($this->report()) implements PlanFactSourceSnapshotReport
        {
            public array $reportCalls = [];

            public array $drillCalls = [];

            public function __construct(private array $payload) {}

            public function reportForProjectScope(array $input, array $projectIds): array
            {
                $this->reportCalls[] = [$input, $projectIds];

                return $this->payload;
            }

            public function drillDownForProjectScope(array $input, array $projectIds): array
            {
                $this->drillCalls[] = [$input, $projectIds];

                return [
                    'items' => [$this->drill($input['drill_down_key'] === 'first-key' ? 101 : 102)],
                    'meta' => ['total' => 1],
                ];
            }

            private function drill(int $sourceId): array
            {
                return [
                    'source_type' => 'payment_transaction',
                    'source_id' => $sourceId,
                    'number' => 'secret-number',
                    'title' => 'secret-title',
                    'date' => '2026-01-15',
                    'amount' => 10.0,
                    'currency' => 'RUB',
                    'status' => 'completed',
                    'route_hint' => ['api_path' => '/secret'],
                    'variance_contribution' => -10.0,
                ];
            }
        };
        $store = new class implements ReportSourceSnapshotStreamingStore
        {
            public ?ReportSourceSnapshotStream $stream = null;

            public ?ReportSourceSnapshotWrite $write = null;

            public int $drillRows = 0;

            public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                $this->write = $snapshot;

                return $snapshot->header;
            }

            public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader
            {
                return null;
            }

            public function resolveReady(
                ReportSourceSnapshotIdentity $identity,
                ReportSourceSnapshotWrite $snapshot,
            ): ReportSourceSnapshotHeader {
                return $this->persistReady($snapshot);
            }

            public function resolveReadyStreamed(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotStream $snapshot): ReportSourceSnapshotHeader
            {
                $this->stream = $snapshot;
                foreach ($snapshot->drillRows() as $_) {
                    $this->drillRows++;
                }

                return $snapshot->header(new Sha256Hash(str_repeat('a', 64)), $this->drillRows, new Sha256Hash(str_repeat('b', 64)));
            }

            public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
            {
                throw new \LogicException;
            }

            public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
            {
                throw new \LogicException;
            }

            public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
            {
                throw new \LogicException;
            }
        };
        $writer = new PlanFactSourceSnapshotWriter($report, new PlanFactSourceSnapshotMaterializer, $store, $this->closeService());

        $header = $writer->persist($this->request([10, 20]));

        self::assertSame('budget_plan_fact', $header->reportCode);
        self::assertSame([[10, 20]], array_map(static fn (array $call): array => $call[1], $report->reportCalls));
        self::assertSame([[10, 20], [10, 20]], array_map(static fn (array $call): array => $call[1], $report->drillCalls));
        self::assertTrue($report->reportCalls[0][0]['_skip_data_mart_meta']);
        self::assertCount(2, $store->stream?->rows ?? []);
    }

    public function test_writer_streams_every_drill_page_to_a_storage_level_writer(): void
    {
        $report = new class implements PlanFactSourceSnapshotReport
        {
            public function reportForProjectScope(array $input, array $projectIds): array
            {
                return [
                    'filters' => ['budget_version_uuid' => 'budget-1', 'scenario_uuid' => 'scenario-1'],
                    'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
                    'sources_coverage' => [],
                    'rows' => [
                        [
                            'group' => ['month' => '2026-01', 'project' => 10, 'currency' => 'RUB'],
                            'currency' => 'RUB',
                            'plan_amount' => 100.0,
                            'forecast_amount' => 110.0,
                            'actual_amount' => 90.0,
                            'committed_amount' => 5.0,
                            'variance_amount' => 10.0,
                            'variance_percent' => 10.0,
                            'risk_level' => 'medium',
                            'drill_down_key' => 'only-key',
                        ],
                    ],
                ];
            }

            public function drillDownForProjectScope(array $input, array $projectIds): array
            {
                $page = (int) $input['page'];

                return [
                    'items' => $page === 1 ? [[
                        'source_type' => 'payment_transaction',
                        'source_id' => 101,
                        'date' => '2026-01-15',
                        'amount' => 10.0,
                        'currency' => 'RUB',
                        'status' => 'completed',
                        'variance_contribution' => -10.0,
                    ]] : [[
                        'source_type' => 'payment_transaction',
                        'source_id' => 102,
                        'date' => '2026-01-16',
                        'amount' => 20.0,
                        'currency' => 'RUB',
                        'status' => 'completed',
                        'variance_contribution' => -20.0,
                    ]],
                    'meta' => ['total' => 2],
                ];
            }
        };
        $store = new class implements ReportSourceSnapshotStreamingStore
        {
            public int $streamCalls = 0;

            public int $drillRowCount = 0;

            public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                throw new \LogicException;
            }

            public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader
            {
                return null;
            }

            public function resolveReady(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                return $snapshot->header;
            }

            public function resolveReadyStreamed(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotStream $snapshot): ReportSourceSnapshotHeader
            {
                $this->streamCalls++;
                foreach ($snapshot->drillRows() as $_) {
                    $this->drillRowCount++;
                }

                return $snapshot->header(new Sha256Hash(str_repeat('a', 64)), $this->drillRowCount, new Sha256Hash(str_repeat('b', 64)));
            }

            public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
            {
                throw new \LogicException;
            }

            public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
            {
                throw new \LogicException;
            }

            public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
            {
                throw new \LogicException;
            }
        };

        (new PlanFactSourceSnapshotWriter($report, new PlanFactSourceSnapshotMaterializer, $store, $this->closeService()))->persist($this->request([10]));

        self::assertSame(1, $store->streamCalls);
        self::assertSame(2, $store->drillRowCount);
    }

    public function test_stream_preserves_the_existing_canonical_source_and_snapshot_hashes(): void
    {
        $report = $this->report();
        $drills = [
            'first-key' => [$this->drill(101)],
            'second-key' => [$this->drill(102)],
        ];
        $materializer = new PlanFactSourceSnapshotMaterializer;
        $expected = $materializer->materialize(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            $this->scope([10, 20]),
            $this->filters(),
            $report,
            $drills,
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-31T10:05:00+00:00'),
            $this->close(),
        );
        $stream = $materializer->stream(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            $this->scope([10, 20]),
            $this->filters(),
            $report,
            static fn (string $key): iterable => $drills[$key],
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-31T10:05:00+00:00'),
            $this->close(),
        );
        $streamDrills = $this->orderedStreamDrills($stream);
        $sourceHash = ReportSourceSnapshotIntegrity::materializedSourceHash($stream->rows, $streamDrills, $stream->watermarks);
        $writing = $stream->header($sourceHash, count($streamDrills), new Sha256Hash(str_repeat('a', 64)));
        $snapshotHash = ReportSourceSnapshotIntegrity::hashStream($writing, $stream->rows, $streamDrills);

        self::assertSame($expected->header->sourceHash->value, $sourceHash->value);
        self::assertSame($expected->header->snapshotHash->value, $snapshotHash->value);
    }

    public function test_source_hash_changes_when_the_validated_close_identity_changes(): void
    {
        $first = $this->materialize($this->report());
        $other = new BudgetingReportSourceClose(
            closeId: '01K00000000000000000000000',
            reportCode: 'budget_plan_fact',
            identity: $this->identity(),
            sourceWatermarks: $this->close()->sourceWatermarks,
            formulaVersion: 'margin-v1',
            sourceManifest: $this->close()->sourceManifest,
            contentHash: str_repeat('b', 64),
            approvedBy: 1,
            approvedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            retainedUntil: new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
            status: BudgetingReportSourceCloseStatus::APPROVED,
            restatesCloseId: null,
        );

        $second = $this->materialize($this->report(), $other);

        self::assertNotSame($first->header->sourceHash->value, $second->header->sourceHash->value);
        self::assertNotSame($first->header->snapshotHash->value, $second->header->snapshotHash->value);
    }

    public function test_empty_project_scope_is_forwarded_as_empty_set_not_legacy_scope(): void
    {
        $report = new class implements PlanFactSourceSnapshotReport
        {
            public array $projectIds = [];

            public function reportForProjectScope(array $input, array $projectIds): array
            {
                $this->projectIds = $projectIds;

                return ['filters' => [], 'period' => [], 'rows' => [], 'sources_coverage' => []];
            }

            public function drillDownForProjectScope(array $input, array $projectIds): array
            {
                throw new \LogicException;
            }
        };
        $store = new class implements ReportSourceSnapshotStreamingStore
        {
            public ?ReportSourceSnapshotStream $stream = null;

            public ?ReportSourceSnapshotWrite $write = null;

            public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                $this->write = $snapshot;

                return $snapshot->header;
            }

            public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader
            {
                return null;
            }

            public function resolveReady(
                ReportSourceSnapshotIdentity $identity,
                ReportSourceSnapshotWrite $snapshot,
            ): ReportSourceSnapshotHeader {
                return $this->persistReady($snapshot);
            }

            public function resolveReadyStreamed(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotStream $snapshot): ReportSourceSnapshotHeader
            {
                $this->stream = $snapshot;

                return $snapshot->header(new Sha256Hash(str_repeat('a', 64)), 0, new Sha256Hash(str_repeat('b', 64)));
            }

            public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
            {
                throw new \LogicException;
            }

            public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
            {
                throw new \LogicException;
            }

            public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
            {
                throw new \LogicException;
            }
        };

        (new PlanFactSourceSnapshotWriter($report, new PlanFactSourceSnapshotMaterializer, $store, $this->closeService()))->persist($this->request([]));

        self::assertSame([], $report->projectIds);
        self::assertCount(0, $store->stream?->rows ?? []);
    }

    public function test_writer_rejects_requested_project_outside_scope_and_service_keeps_legacy_entrypoints(): void
    {
        self::assertTrue(is_a(PlanFactReportService::class, PlanFactSourceSnapshotReport::class, true));
        self::assertTrue(method_exists(PlanFactReportService::class, 'report'));
        self::assertTrue(method_exists(PlanFactReportService::class, 'drillDown'));

        $this->expectException(InvalidArgumentException::class);
        new PlanFactSourceSnapshotRequest(
            $this->scope([10]),
            ['organization_id' => 1, 'project_id' => 20],
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            $this->identity(),
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            null,
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );
    }

    private function materialize(array $report, ?BudgetingReportSourceClose $close = null): ReportSourceSnapshotWrite
    {
        return (new PlanFactSourceSnapshotMaterializer)->materialize(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            $this->scope([10, 20]),
            $this->filters(),
            $report,
            [
                'first-key' => [$this->drill(101)],
                'second-key' => [$this->drill(102)],
            ],
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-31T10:05:00+00:00'),
            $close ?? $this->close(),
        );
    }

    /** @return list<ReportSourceSnapshotDrillRow> */
    private function orderedStreamDrills(ReportSourceSnapshotStream $stream): array
    {
        $grouped = [];
        foreach ($stream->drillRows() as $row) {
            $grouped[$row->rowKey][$row->columnId][] = $row;
        }
        $result = [];
        foreach ($grouped as $rowKey => $columns) {
            foreach ($columns as $columnId => $rows) {
                usort($rows, static fn ($left, $right): int => $left->sortKey <=> $right->sortKey);
                foreach ($rows as $ordinal => $row) {
                    $result[] = new ReportSourceSnapshotDrillRow(
                        $stream->id,
                        $rowKey,
                        $columnId,
                        $ordinal + 1,
                        $row->payload,
                        $row->payloadHash,
                    );
                }
            }
        }
        usort($result, static fn (ReportSourceSnapshotDrillRow $left, ReportSourceSnapshotDrillRow $right): int => [$left->rowKey, $left->columnId, $left->ordinal] <=> [$right->rowKey, $right->columnId, $right->ordinal]);

        return $result;
    }

    private function request(array $projectIds): PlanFactSourceSnapshotRequest
    {
        return new PlanFactSourceSnapshotRequest(
            $this->scope($projectIds),
            $this->filters(),
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            $this->identity(),
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-31T10:05:00+00:00'),
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );
    }

    private function scope(array $projectIds): ReportScope
    {
        return new ReportScope(1, [1], $projectIds, [], new DateTimeZone('UTC'));
    }

    private function filters(): array
    {
        return [
            'organization_id' => 1,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'budget_version_uuid' => 'budget-1',
            'scenario_uuid' => 'scenario-1',
            'group_by' => ['month', 'project', 'currency'],
        ];
    }

    private function identity(): BudgetingReportSourceCloseIdentity
    {
        return new BudgetingReportSourceCloseIdentity(1, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1');
    }

    private function close(): BudgetingReportSourceClose
    {
        return new BudgetingReportSourceClose(
            closeId: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            reportCode: 'budget_plan_fact',
            identity: $this->identity(),
            sourceWatermarks: [
                new BudgetingReportSourceWatermark('actuals', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'actuals:1', 'actuals-v1'),
                new BudgetingReportSourceWatermark('budget', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'budget:1', 'budget-v1'),
            ],
            formulaVersion: 'margin-v1',
            sourceManifest: ['actuals' => ['version' => 'actuals:1'], 'budget' => ['version' => 'budget:1']],
            contentHash: str_repeat('a', 64),
            approvedBy: 1,
            approvedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            retainedUntil: new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
            status: BudgetingReportSourceCloseStatus::APPROVED,
            restatesCloseId: null,
        );
    }

    private function closeService(): BudgetingReportSourceCloseService
    {
        return new BudgetingReportSourceCloseService(new class($this->close()) implements BudgetingReportSourceCloseStore
        {
            public function __construct(private readonly BudgetingReportSourceClose $close) {}

            public function createApproved(\App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
            {
                throw new \LogicException;
            }

            public function find(string $closeId): ?BudgetingReportSourceClose
            {
                return $closeId === $this->close->closeId ? $this->close : null;
            }
        });
    }

    private function report(): array
    {
        return [
            'filters' => ['budget_version_uuid' => 'budget-1', 'scenario_uuid' => 'scenario-1'],
            'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
            'sources_coverage' => [
                ['source_type' => 'budget_amounts', 'included_aggregate_rows' => 2],
                ['source_type' => 'payment_transactions', 'included_aggregate_rows' => 1],
            ],
            'totals_by_currency' => [[
                'currency' => 'RUB',
                'plan_amount' => 300.0,
                'forecast_amount' => 320.0,
                'actual_amount' => 280.0,
                'committed_amount' => 10.0,
                'variance_amount' => 20.0,
                'variance_percent' => 6.67,
                'risk_level' => 'medium',
                'rows_count' => 2,
            ]],
            'rows' => [
                $this->row('second-key', 20, 'Sensitive project B', 200.0),
                $this->row('first-key', 10, 'Sensitive project A', 100.0),
            ],
        ];
    }

    private function row(string $drillKey, int $projectId, string $projectName, float $plan): array
    {
        return [
            'group' => ['month' => '2026-01', 'project' => $projectId, 'currency' => 'RUB'],
            'budget_article' => ['id' => 'article-1', 'name' => 'Sensitive article'],
            'responsibility_center' => ['id' => 'center-1', 'name' => 'Sensitive center'],
            'project' => ['id' => $projectId, 'name' => $projectName],
            'counterparty' => ['id' => 1, 'name' => 'Sensitive counterparty'],
            'scenario' => ['id' => 'scenario-1', 'name' => 'Sensitive scenario'],
            'currency' => 'RUB',
            'plan_amount' => $plan,
            'forecast_amount' => $plan + 10.0,
            'actual_amount' => $plan - 10.0,
            'committed_amount' => 5.0,
            'variance_amount' => 10.0,
            'variance_percent' => 10.0,
            'risk_level' => 'medium',
            'drill_down_key' => $drillKey,
        ];
    }

    private function drill(int $sourceId): array
    {
        return [
            'source_type' => 'payment_transaction',
            'source_id' => $sourceId,
            'number' => 'secret-number',
            'title' => 'secret-title',
            'date' => '2026-01-15',
            'amount' => 10.0,
            'currency' => 'RUB',
            'status' => 'completed',
            'route_hint' => ['api_path' => '/secret'],
            'variance_contribution' => -10.0,
        ];
    }
}

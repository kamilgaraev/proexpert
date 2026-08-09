<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceSnapshotWriter;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleLineResult;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleMetric;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSnapshotRequest;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSourceRead;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementCycleStage;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReadinessProbe;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportAdapter;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportBindingFactory;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleSourceSnapshotMaterializer;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ProcurementCycleRuntimeContractTest extends TestCase
{
    public function test_snapshot_request_ignores_canonical_scope_identity_filters(): void
    {
        $scope = (new ReportExecutionContextBuilder)->build()->scope;

        $request = new ProcurementCycleSnapshotRequest(
            $scope,
            [
                'organization_id' => (string) $scope->organizationId,
                'project_id' => (string) $scope->projectIds[0],
                'period_start' => '2026-07-10',
                'period_end' => '2026-08-09',
            ],
            new DateTimeImmutable('2026-08-09T10:00:00+00:00'),
            null,
        );

        self::assertSame([
            'period_end' => '2026-08-09',
            'period_start' => '2026-07-10',
        ], $request->filters);
    }

    public function test_snapshot_request_rejects_project_scope_escape(): void
    {
        $scope = (new ReportExecutionContextBuilder)->build()->scope;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('procurement_cycle_snapshot_scope_invalid');

        new ProcurementCycleSnapshotRequest(
            $scope,
            ['project_ids' => [999999]],
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            null,
        );
    }

    public function test_empty_authorized_source_materializes_one_integrity_bound_ready_candidate(): void
    {
        $scope = (new ReportExecutionContextBuilder)->build()->scope;
        $request = new ProcurementCycleSnapshotRequest(
            $scope,
            [],
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            null,
        );
        $source = new ProcurementCycleSourceRead([], 0, 0, 0, 0, null, 0, null);
        $materializer = new ProcurementCycleSourceSnapshotMaterializer;

        $write = $materializer->materialize(
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            $request,
            $source,
            [],
            [],
        );

        ReportSourceSnapshotIntegrity::assertWrite($write);
        self::assertSame(ProcurementCycleReportAdapter::SOURCE_KIND, $write->header->sourceKind);
        self::assertSame(ProcurementCycleReportAdapter::FORMULA_VERSION, $write->header->watermarks['formula_version']);
        self::assertSame(0, $write->header->rowCount);
        self::assertSame(
            $materializer->identity($request, $source)->queryHash->value,
            $write->header->queryHash->value,
        );
    }

    public function test_source_snapshot_reports_progress_while_materializing_rows(): void
    {
        $scope = (new ReportExecutionContextBuilder)->build()->scope;
        $request = new ProcurementCycleSnapshotRequest(
            $scope,
            [],
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            null,
        );
        $reported = [];
        $progress = new ReportProgress(40, static function (ReportProgress $current) use (&$reported): void {
            $reported[] = $current->percent();
        });

        (new ProcurementCycleSourceSnapshotMaterializer)->materialize(
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            $request,
            new ProcurementCycleSourceRead([], 1, 1, 0, 0, null, 0, null),
            [$this->publicResult()],
            [],
            null,
            $progress,
        );

        self::assertSame(90, $progress->percent());
        self::assertGreaterThanOrEqual(2, count($reported));
    }

    public function test_source_snapshot_identity_changes_with_the_immutable_report_query_identity(): void
    {
        $scope = (new ReportExecutionContextBuilder)->build()->scope;
        $request = new ProcurementCycleSnapshotRequest($scope, [], new DateTimeImmutable('2026-08-01T10:00:00+00:00'), null);
        $source = new ProcurementCycleSourceRead([], 0, 0, 0, 0, null, 0, null);
        $definition = (new ReportDefinitionBuilder)
            ->code(ProcurementCycleReportAdapter::REPORT_CODE)
            ->formulaVersion(ProcurementCycleReportAdapter::FORMULA_VERSION)
            ->sourceSchemaVersion(ProcurementCycleReportAdapter::SCHEMA_VERSION)
            ->payload();
        $first = new ReportQuery($definition, $scope, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet([]), [], new DateTimeImmutable('2026-08-01T10:00:00+00:00'), 'ru');
        $second = new ReportQuery($definition, $scope, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet([]), ['period' => 'previous'], new DateTimeImmutable('2026-08-01T11:00:00+00:00'), 'en');
        $materializer = new ProcurementCycleSourceSnapshotMaterializer;

        self::assertNotSame(
            $materializer->identity($request, $source, $first->identity)->queryHash->value,
            $materializer->identity($request, $source, $second->identity)->queryHash->value,
        );
    }

    public function test_binding_uses_one_production_adapter_for_data_rows_and_drill(): void
    {
        $definition = (new ReportDefinitionBuilder)
            ->code(ProcurementCycleReportAdapter::REPORT_CODE)
            ->contractVersion('1.0.0')
            ->formulaVersion(ProcurementCycleReportAdapter::FORMULA_VERSION)
            ->sourceSchemaVersion(ProcurementCycleReportAdapter::SCHEMA_VERSION)
            ->sorts([['id' => ProcurementCycleReportAdapter::SORT_FIELD]])
            ->formats(['csv', 'xlsx', 'pdf'])
            ->payload();
        $adapter = new ProcurementCycleReportAdapter($this->writer(), $this->store());
        $readiness = new ProcurementCycleReadinessProbe;

        $binding = (new ProcurementCycleReportBindingFactory($adapter, $readiness))->create($definition);

        self::assertSame($adapter, $binding->dataProvider);
        self::assertSame($adapter, $binding->rowQuery);
        self::assertSame($adapter, $binding->drillDownProvider);
        self::assertSame($readiness, $binding->readinessProbe);
        self::assertTrue($readiness->supports($definition));
    }

    public function test_page_export_and_drill_whitelist_technical_source_fields(): void
    {
        $scope = (new ReportExecutionContextBuilder)->build()->scope;
        $request = new ProcurementCycleSnapshotRequest(
            $scope,
            [],
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            null,
        );
        $write = (new ProcurementCycleSourceSnapshotMaterializer)->materialize(
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            $request,
            new ProcurementCycleSourceRead([], 1, 1, 1, 101, '2026-08-01T10:00:00.000000Z', 1, '2026-08-01T10:00:00.000000Z'),
            [$this->publicResult()],
            [3 => [new class
            {
                public function auditPayload(): array
                {
                    return [
                        'event_id' => 1,
                        'event_code' => 'request_created',
                        'occurred_at' => '2026-08-01T10:00:00.000000Z',
                        'policy_hash' => str_repeat('a', 64),
                        'calendar_hash' => str_repeat('b', 64),
                        'calendar_version' => 'calendar.v1',
                        'source_kind' => 'internal',
                    ];
                }
            }]],
        );

        self::assertSame([
            'purchase_request_line_id', 'request_number', 'material_name', 'requester_id', 'buyer_id',
            'priority', 'current_stage', 'outcome', 'total_cycle_seconds', 'open_age_seconds',
            'awarded_supplier_party_id', 'awarded_amount', 'currency', 'quality_status', 'gap_codes',
            'cohort_date', 'stage_breakdown', 'audit_timeline',
        ], array_keys($write->rows[0]->payload));
        $context = (new ReportExecutionContextBuilder)->build();
        $snapshot = new ReportSnapshotRef(
            ProcurementCycleReportAdapter::SOURCE_KIND,
            $write->header->id,
            $context->scope,
            new Sha256Hash(str_repeat('c', 64)),
            ProcurementCycleReportAdapter::FORMULA_VERSION,
            $write->header->sourceHash,
            $write->header->generatedAt,
            $write->header->staleAt,
            [
                ...$write->header->watermarks,
                'report_query_hash' => str_repeat('d', 64),
                'source_snapshot_query_hash' => $write->header->queryHash->value,
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $adapter = new ProcurementCycleReportAdapter($this->writer(), $this->storeFor($write));
        $result = $adapter->result($context, $snapshot);
        $page = $adapter->page(
            $context,
            $snapshot,
            new ReportWindowSort(ProcurementCycleReportAdapter::SORT_FIELD, ReportSortDirection::ASC),
            null,
            100,
        );
        $export = iterator_to_array($adapter->cursor(
            $context,
            $snapshot,
            new ReportWindowSort(ProcurementCycleReportAdapter::SORT_FIELD, ReportSortDirection::ASC),
            100,
        ));
        $drill = $adapter->drillDown(
            $context,
            $snapshot,
            new ReportDrillDownInput(
                new ReportDrillDownCell('procurement-line:3', ProcurementCycleReportAdapter::AUDIT_DRILL_COLUMN),
                null,
                100,
            ),
        );
        foreach ([$page->rows[0], $export[0]['values'], $drill->rows[0]] as $payload) {
            foreach (['policy_hash', 'calendar_hash', 'calendar_version', 'supplier_snapshot'] as $key) {
                self::assertArrayNotHasKey($key, $payload);
            }
        }
        self::assertSame(1, $write->header->watermarks['unscoped_quarantine_line_count']);
        self::assertSame(ReportQualityStatus::PARTIAL, $result->quality->status);
        self::assertSame('2', $result->quality->coverage?->denominator);
    }

    public function test_only_quarantine_source_has_a_distinct_deterministic_watermark(): void
    {
        $scope = (new ReportExecutionContextBuilder)->build()->scope;
        $request = new ProcurementCycleSnapshotRequest(
            $scope,
            [],
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            null,
        );
        $materializer = new ProcurementCycleSourceSnapshotMaterializer;
        $first = new ProcurementCycleSourceRead([], 0, 0, 1, 101, '2026-08-01T09:00:00.000000Z', 0, null);
        $next = new ProcurementCycleSourceRead([], 0, 0, 1, 102, '2026-08-01T09:01:00.000000Z', 0, null);

        self::assertNotSame(
            $materializer->identity($request, $first)->sourceVersion,
            $materializer->identity($request, $next)->sourceVersion,
        );
        $write = $materializer->materialize('01JZZZZZZZZZZZZZZZZZZZZZZZ', $request, $first, [], []);

        self::assertSame(1, $write->header->watermarks['unscoped_quarantine_line_count']);
        self::assertSame(101, $write->header->watermarks['unscoped_quarantine_max_event_id']);
        self::assertSame('2026-08-01T09:00:00.000000Z', $write->header->watermarks['unscoped_quarantine_max_occurred_at']);
    }

    private function publicResult(): ProcurementCycleLineResult
    {
        $stageMetrics = [];
        foreach (ProcurementCycleStage::cases() as $stage) {
            $stageMetrics[$stage->value] = new ProcurementCycleMetric(null, null, null, 3600, false, null, null);
        }

        return new ProcurementCycleLineResult(
            organizationId: 1,
            projectId: 1,
            purchaseRequestId: 2,
            purchaseRequestLineId: 3,
            dimensions: [
                'request_number' => 'PR-1',
                'material_name' => 'Материал',
                'requester_id' => 4,
                'buyer_id' => 5,
                'priority' => 'normal',
                'policy_hash' => str_repeat('a', 64),
                'calendar_hash' => str_repeat('b', 64),
                'calendar_version' => 'calendar.v1',
                'supplier_snapshot' => 'restricted',
            ],
            solicitedSupplierIds: [],
            awardedSupplierPartyId: 6,
            awardedAmount: '10.00',
            currency: 'RUB',
            outcome: 'open',
            currentStage: ProcurementCycleStage::REQUEST_APPROVAL,
            startCohortDate: '2026-08-01',
            outcomeCohortDate: null,
            boundaryTimes: [],
            stageMetrics: $stageMetrics,
            openAgeSeconds: 1,
            totalCycleSeconds: null,
            timeToCancellationSeconds: null,
            totalSlaEligible: false,
            totalSlaMet: null,
            qualityStatus: 'FULL',
            gapCodes: [],
        );
    }

    private function writer(): ProcurementCycleSourceSnapshotWriter
    {
        return new class implements ProcurementCycleSourceSnapshotWriter
        {
            public function persist(ReportQuery $query, ReportProgress $progress): ReportSourceSnapshotHeader
            {
                throw new LogicException('not used');
            }
        };
    }

    private function store(): ReportSourceSnapshotStore
    {
        return new class implements ReportSourceSnapshotStore
        {
            public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                throw new LogicException;
            }

            public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader
            {
                throw new LogicException;
            }

            public function resolveReady(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                throw new LogicException;
            }

            public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
            {
                throw new LogicException;
            }

            public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
            {
                throw new LogicException;
            }

            public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
            {
                throw new LogicException;
            }
        };
    }

    private function storeFor(ReportSourceSnapshotWrite $write): ReportSourceSnapshotStore
    {
        return new class($write) implements ReportSourceSnapshotStore
        {
            public function __construct(private ReportSourceSnapshotWrite $write) {}

            public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                throw new LogicException;
            }

            public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader
            {
                throw new LogicException;
            }

            public function resolveReady(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                throw new LogicException;
            }

            public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
            {
                return $this->write->header;
            }

            public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
            {
                return new ReportSourceSnapshotPage($this->write->rows, null);
            }

            public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
            {
                return new ReportSourceSnapshotDrillPage(array_values(array_filter(
                    $this->write->drillRows,
                    static fn ($row): bool => $row->columnId === $columnId,
                )), null);
            }
        };
    }
}

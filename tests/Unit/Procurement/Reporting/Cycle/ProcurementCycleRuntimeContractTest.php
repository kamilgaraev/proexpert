<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
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
        $source = new ProcurementCycleSourceRead([], 0, 0, 0, null);
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

    public function test_source_snapshot_whitelists_public_row_fields(): void
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
            new ProcurementCycleSourceRead([], 1, 1, 1, '2026-08-01T10:00:00.000000Z'),
            [$this->publicResult()],
            [],
        );

        self::assertSame([
            'purchase_request_line_id', 'request_number', 'material_name', 'requester_id', 'buyer_id',
            'priority', 'current_stage', 'outcome', 'total_cycle_seconds', 'open_age_seconds',
            'awarded_supplier_party_id', 'awarded_amount', 'currency', 'quality_status', 'gap_codes',
            'cohort_date', 'stage_breakdown', 'audit_timeline',
        ], array_keys($write->rows[0]->payload));
        self::assertArrayNotHasKey('policy_hash', $write->rows[0]->payload);
        self::assertArrayNotHasKey('supplier_snapshot', $write->rows[0]->payload);
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
            public function persist(ReportQuery $query): ReportSourceSnapshotHeader
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
}

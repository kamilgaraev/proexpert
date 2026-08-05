<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\Backfill\HoldingPerformanceBackfill;
use App\BusinessModules\Core\MultiOrganization\Reporting\Providers\HoldingPerformanceReportProvider;
use App\BusinessModules\Core\MultiOrganization\Reporting\Providers\IntercompanyContractFlowsReportProvider;
use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\HoldingPerformanceRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\IntercompanyContractFlowRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\AcceptedWorkHoldingFactProducer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceSnapshotMaterializer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowFormula;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursorKeyset;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioQueryService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PortfolioOwnerPortContractTest extends TestCase
{
    #[Test]
    public function holding_owner_has_transactional_accepted_fact_and_resumable_backfill_ports(): void
    {
        self::assertTrue((new ReflectionClass(AcceptedWorkHoldingFactProducer::class))->hasMethod('project'));
        $backfill = new ReflectionClass(HoldingPerformanceBackfill::class);

        self::assertTrue($backfill->hasMethod('projectAllocationVersions'));
        self::assertTrue($backfill->hasMethod('projectApprovedActs'));
        self::assertTrue($backfill->hasMethod('projectPaidTransactions'));
    }

    #[Test]
    public function holding_providers_and_queries_keep_exact_port_split(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(HoldingPerformanceReportProvider::class));
        self::assertContains(ReportDataProvider::class, class_implements(IntercompanyContractFlowsReportProvider::class));
        self::assertNotContains(ReportRowQuery::class, class_implements(HoldingPerformanceReportProvider::class));
        self::assertNotContains(ReportRowQuery::class, class_implements(IntercompanyContractFlowsReportProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(HoldingPerformanceRowQuery::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(HoldingPerformanceRowQuery::class));
        self::assertContains(ReportRowQuery::class, class_implements(IntercompanyContractFlowRowQuery::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(IntercompanyContractFlowRowQuery::class));
    }

    #[Test]
    #[DataProvider('opaquePortCases')]
    public function mismatched_typed_cursor_and_unsupported_drill_down_fail_before_source_lookup(
        ReportRowQuery&ReportDrillDownProvider $query,
        string $kind,
        string $sortField,
    ): void {
        $context = $this->context();
        $snapshot = $this->snapshot($context, $kind);
        $sort = new ReportWindowSort($sortField, ReportSortDirection::ASC);
        $cursor = new ReportCursor(
            'opaque.signed',
            '01J00000000000000000000000',
            $this->hash('c'),
            $this->hash('wrong-source'),
            $sort,
            new ReportCursorKeyset('last', 'row-1'),
            new DateTimeImmutable('+1 hour'),
        );

        try {
            $query->page($context, $snapshot, $sort, $cursor, 10);
            self::fail('Opaque cursor must be rejected before any owner query.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_CURSOR_INVALID, $exception->errorCode);
        }

        try {
            $query->drillDown(
                $context,
                $snapshot,
                new ReportDrillDownInput(new ReportDrillDownCell('row-1', 'unsupported_column'), null, 10),
            );
            self::fail('Unsupported drill-down column must be rejected before source lookup.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_FILTER_UNSUPPORTED, $exception->errorCode);
        }
    }

    public static function opaquePortCases(): array
    {
        $holding = (new ReflectionClass(HoldingPerformanceSnapshotMaterializer::class))
            ->newInstanceWithoutConstructor();
        $intercompany = (new ReflectionClass(IntercompanyContractFlowSnapshotMaterializer::class))
            ->newInstanceWithoutConstructor();

        return [
            'project portfolio health' => [new BudgetingPortfolioQueryService, 'project_portfolio_health', 'risk_rank'],
            'portfolio liquidity' => [new BudgetingPortfolioQueryService, 'portfolio_liquidity', 'forecast_date'],
            'holding performance' => [new HoldingPerformanceRowQuery($holding), 'holding_performance', 'period_start'],
            'intercompany flows' => [new IntercompanyContractFlowRowQuery($intercompany), 'intercompany_contract_flows', 'period_start'],
        ];
    }

    private function context(): ReportExecutionContext
    {
        $scope = new ReportScope(1, [1], [], [], new DateTimeZone('UTC'));

        return new ReportExecutionContext(
            new ReportActor(10, 'active', ['reports.view']),
            $scope,
            new ReportVisibility(true, false, false, false, false, false, false),
            new AuthorizationDecisionContext('http', 1, [1], [], [], new DateTimeZone('UTC'), 'test-correlation', null),
        );
    }

    private function snapshot(ReportExecutionContext $context, string $kind): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            $kind,
            'snapshot_1',
            $context->scope,
            $this->hash('a'),
            'formula_v1',
            $this->hash('b'),
            new DateTimeImmutable('2026-07-29T00:00:00+00:00'),
            null,
            ['query_hash' => $this->hash('c')->value],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function hash(string $seed): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', $seed));
    }
}

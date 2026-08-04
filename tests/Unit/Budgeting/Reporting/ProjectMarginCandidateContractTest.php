<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceWatermark;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotMaterializer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectMarginCandidateContractTest extends TestCase
{
    public function test_contract_locks_runtime_schema_columns_permissions_and_delivery(): void
    {
        $contract = new ProjectMarginCandidateContract;

        $contract->assertRuntimeMatches();

        self::assertSame(ProjectMarginCandidateContract::CODE, ProjectMarginSourceSnapshotMaterializer::REPORT_CODE);
        self::assertSame('margin-v1', $contract->formulaVersion);
        self::assertSame(ProjectMarginSourceSnapshotMaterializer::SCHEMA_VERSION, $contract->sourceSchemaVersion);
        self::assertSame(['csv', 'xlsx'], $contract->formats());
        self::assertSame([
            'actual', 'currency', 'drill', 'forecast', 'group', 'plan', 'problem_flags', 'quality_status',
            'risk_flags', 'row_key', 'source_rows_count', 'source_types', 'variance',
        ], array_column($contract->columns(), 'id'));
        self::assertSame([['id' => 'row_key', 'direction' => 'asc']], $contract->sorts());
        self::assertSame('attributions', $contract->drillColumnId());
    }

    public function test_rejects_formula_schema_source_and_sort_drift(): void
    {
        foreach ([
            new ProjectMarginCandidateContract(formulaHash: str_repeat('0', 64)),
            new ProjectMarginCandidateContract(sourceSchemaVersion: '9.9.9'),
            new ProjectMarginCandidateContract(sourceHash: str_repeat('0', 64)),
        ] as $contract) {
            try {
                $contract->assertRuntimeMatches();
                self::fail('Expected contract drift to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('project_margin_candidate_contract_drift', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        (new ProjectMarginCandidateContract)->assertSort(new ReportWindowSort('currency', ReportSortDirection::ASC));
    }

    public function test_snapshot_request_requires_exact_server_scope_close_identity_formula_and_grouping(): void
    {
        $contract = new ProjectMarginCandidateContract;
        $scope = new ReportScope(1, [1], [10], [], new \DateTimeZone('UTC'));
        $filters = [
            'organization_id' => 1,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'scenario_uuid' => 'scenario-1',
            'budget_version_uuid' => 'budget-1',
            'project_id' => 10,
            'group_by' => ProjectMarginReportFilters::DEFAULT_GROUP_BY,
        ];

        $contract->assertSnapshotRequest($scope, $filters, $this->close());
        self::addToAssertionCount(1);

        $filters['group_by'] = [ProjectMarginReportFilters::GROUP_PROJECT, ProjectMarginReportFilters::GROUP_CURRENCY];
        $contract->assertSnapshotRequest($scope, $filters, $this->close());
        self::addToAssertionCount(1);

        $filters['organization_id'] = 2;
        $this->expectException(InvalidArgumentException::class);
        $contract->assertSnapshotRequest($scope, $filters, $this->close());
    }

    public function test_snapshot_request_rejects_unknown_or_non_currency_grouping(): void
    {
        $contract = new ProjectMarginCandidateContract;
        $scope = new ReportScope(1, [1], [10], [], new \DateTimeZone('UTC'));
        $filters = [
            'organization_id' => 1,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'scenario_uuid' => 'scenario-1',
            'budget_version_uuid' => 'budget-1',
            'project_id' => 10,
            'group_by' => [ProjectMarginReportFilters::GROUP_PROJECT],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('project_margin_candidate_grouping_invalid');
        $contract->assertSnapshotRequest($scope, $filters, $this->close());
    }

    public function test_snapshot_request_rejects_a_close_from_another_report(): void
    {
        $scope = new ReportScope(1, [1], [10], [], new \DateTimeZone('UTC'));
        $filters = [
            'organization_id' => 1,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'scenario_uuid' => 'scenario-1',
            'budget_version_uuid' => 'budget-1',
            'project_id' => 10,
            'group_by' => ProjectMarginReportFilters::DEFAULT_GROUP_BY,
        ];

        $this->expectException(InvalidArgumentException::class);
        (new ProjectMarginCandidateContract)->assertSnapshotRequest(
            $scope,
            $filters,
            $this->close(reportCode: 'budget_plan_fact'),
        );
    }

    private function close(
        string $formulaVersion = ProjectMarginCandidateContract::FORMULA_VERSION,
        string $reportCode = ProjectMarginCandidateContract::CODE,
    ): BudgetingReportSourceClose {
        return new BudgetingReportSourceClose(
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            $reportCode,
            new BudgetingReportSourceCloseIdentity(1, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1'),
            [new BudgetingReportSourceWatermark('actuals', new \DateTimeImmutable('2026-01-31T17:00:00Z'), 'actuals:1', 'actuals-v1')],
            $formulaVersion,
            ['actuals' => ['version' => 'actuals:1']],
            str_repeat('a', 64),
            1,
            new \DateTimeImmutable('2026-02-01T00:00:00Z'),
            new \DateTimeImmutable('2033-01-31T00:00:00Z'),
            BudgetingReportSourceCloseStatus::APPROVED,
            null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Budgeting\BudgetPlanFactCandidateFixture;

final class BudgetPlanFactCandidateContractTest extends TestCase
{
    public function test_contract_locks_the_real_runtime_versions_hashes_and_unrendered_formats(): void
    {
        $contract = BudgetPlanFactCandidateFixture::contract();

        $contract->assertRuntimeMatches();

        self::assertSame(BudgetPlanFactCandidateContract::CODE, PlanFactSourceSnapshotMaterializer::REPORT_CODE);
        self::assertSame(PlanFactSourceSnapshotMaterializer::SCHEMA_VERSION, $contract->sourceSchemaVersion);
        self::assertSame([], $contract->formats());
        self::assertSame([
            'actual_amount', 'committed_amount', 'currency', 'drill', 'forecast_amount', 'group', 'plan_amount', 'risk_level',
            'row_key', 'variance_amount', 'variance_percent',
        ], array_column($contract->columns(), 'id'));
        self::assertSame([['id' => 'row_key', 'direction' => 'asc']], $contract->sorts());
        self::assertSame('sources', $contract->drillColumnId());
    }

    public function test_no_db_fixture_is_deterministic_and_matches_the_row_and_drill_contract(): void
    {
        $contract = BudgetPlanFactCandidateFixture::contract();
        $first = BudgetPlanFactCandidateFixture::snapshot();
        $second = BudgetPlanFactCandidateFixture::snapshot();
        $rows = array_map(static fn ($row): array => [...$row->payload, 'row_key' => $row->rowKey], $first->rows);
        $drills = array_map(static fn ($drill): array => [
            'row_key' => $drill->rowKey,
            'column_id' => $drill->columnId,
            'source_ref' => $drill->payload['source_ref'],
        ], $first->drillRows);

        $contract->assertRowsAndDrills($rows, $drills);

        self::assertSame($first->header->sourceHash->value, $second->header->sourceHash->value);
        self::assertSame($first->header->snapshotHash->value, $second->header->snapshotHash->value);
        $rowKeys = array_column($rows, 'row_key');
        $orderedRowKeys = $rowKeys;
        sort($orderedRowKeys, SORT_STRING);
        self::assertSame($orderedRowKeys, $rowKeys);
        self::assertSame(['RUB', 'USD'], array_column($rows, 'currency'));
        self::assertSame([-60.0, 20.0], array_column($rows, 'variance_amount'));
    }

    public function test_rejects_formula_schema_and_source_hash_drift(): void
    {
        foreach ([
            new BudgetPlanFactCandidateContract(formulaHash: str_repeat('0', 64)),
            new BudgetPlanFactCandidateContract(sourceSchemaVersion: '9.9.9'),
            new BudgetPlanFactCandidateContract(sourceHash: str_repeat('0', 64)),
        ] as $contract) {
            try {
                $contract->assertRuntimeMatches();
                self::fail('Expected contract drift to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('budget_plan_fact_candidate_contract_drift', $exception->getMessage());
            }
        }
    }

    public function test_rejects_cross_organization_and_wrong_close_identity(): void
    {
        $contract = BudgetPlanFactCandidateFixture::contract();
        $filters = BudgetPlanFactCandidateFixture::filters();

        $this->expectException(InvalidArgumentException::class);
        $contract->assertSnapshotRequest(
            BudgetPlanFactCandidateFixture::scope(),
            $filters,
            BudgetPlanFactCandidateFixture::closeId(),
            new BudgetingReportSourceCloseIdentity(2, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1'),
            BudgetPlanFactCandidateContract::FORMULA_VERSION,
        );
    }

    public function test_rejects_wrong_close_period_identity(): void
    {
        $contract = BudgetPlanFactCandidateFixture::contract();

        $this->expectException(InvalidArgumentException::class);
        $contract->assertSnapshotRequest(
            BudgetPlanFactCandidateFixture::scope(),
            BudgetPlanFactCandidateFixture::filters(),
            BudgetPlanFactCandidateFixture::closeId(),
            new BudgetingReportSourceCloseIdentity(1, '2026-02-01', '2026-02-28', 'scenario-1', 'budget-1'),
            BudgetPlanFactCandidateContract::FORMULA_VERSION,
        );
    }

    public function test_rejects_close_formula_version_drift(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BudgetPlanFactCandidateFixture::contract()->assertSnapshotRequest(
            BudgetPlanFactCandidateFixture::scope(),
            BudgetPlanFactCandidateFixture::filters(),
            BudgetPlanFactCandidateFixture::closeId(),
            BudgetPlanFactCandidateFixture::closeIdentity(),
            'other-formula',
        );
    }

    public function test_rejects_grouping_drift(): void
    {
        $filters = BudgetPlanFactCandidateFixture::filters();
        $filters['group_by'] = ['month', 'project', 'currency'];

        $this->expectException(InvalidArgumentException::class);
        BudgetPlanFactCandidateFixture::contract()->assertSnapshotRequest(
            BudgetPlanFactCandidateFixture::scope(),
            $filters,
            BudgetPlanFactCandidateFixture::closeId(),
            BudgetPlanFactCandidateFixture::closeIdentity(),
            BudgetPlanFactCandidateContract::FORMULA_VERSION,
        );
    }

    public function test_rejects_unsupported_sort_and_row_drill_mismatch(): void
    {
        $contract = BudgetPlanFactCandidateFixture::contract();

        $this->expectException(InvalidArgumentException::class);
        $contract->assertSort(new ReportWindowSort('currency', ReportSortDirection::ASC));
    }

    public function test_rejects_row_and_drill_mismatch(): void
    {
        $contract = BudgetPlanFactCandidateFixture::contract();
        $snapshot = BudgetPlanFactCandidateFixture::snapshot();
        $rows = array_map(static fn ($row): array => [...$row->payload, 'row_key' => $row->rowKey], $snapshot->rows);

        $this->expectException(InvalidArgumentException::class);
        $contract->assertRowsAndDrills(
            $rows,
            [['row_key' => 'other-row', 'column_id' => 'sources', 'source_ref' => 'source-1']],
        );
    }
}

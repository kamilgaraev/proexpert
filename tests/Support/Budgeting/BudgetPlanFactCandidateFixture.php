<?php

declare(strict_types=1);

namespace Tests\Support\Budgeting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceWatermark;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactSourceAggregate;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\PlanFactCalculator;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use DateTimeImmutable;
use DateTimeZone;

final class BudgetPlanFactCandidateFixture
{
    public static function contract(): BudgetPlanFactCandidateContract
    {
        return new BudgetPlanFactCandidateContract;
    }

    public static function scope(): ReportScope
    {
        return new ReportScope(1, [1], [10, 20], [], new DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    public static function filters(): array
    {
        return [
            'organization_id' => 1,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'scenario_uuid' => 'scenario-1',
            'budget_version_uuid' => 'budget-1',
            'group_by' => PlanFactReportFilters::DEFAULT_GROUP_BY,
        ];
    }

    public static function closeId(): string
    {
        return '01JZZZZZZZZZZZZZZZZZZZZZZZ';
    }

    public static function closeIdentity(): BudgetingReportSourceCloseIdentity
    {
        return new BudgetingReportSourceCloseIdentity(1, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1');
    }

    public static function snapshot(): ReportSourceSnapshotWrite
    {
        $close = self::close();
        self::contract()->assertSnapshotRequest(self::scope(), self::filters(), $close);
        $report = self::report();
        $drills = [];
        foreach ($report['rows'] as $row) {
            $drills[$row['drill_down_key']] = [$row['currency'] === 'RUB'
                ? self::drill('payment_transaction', 101, 160.0, 'RUB')
                : self::drill('budget_amount', 501, 120.0, 'USD')];
        }

        return (new PlanFactSourceSnapshotMaterializer)->materialize(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            self::scope(),
            self::filters(),
            $report,
            $drills,
            new DateTimeImmutable('2026-01-31T10:00:00+00:00'),
            null,
            $close,
        );
    }

    public static function close(
        ?BudgetingReportSourceCloseIdentity $identity = null,
        ?string $formulaVersion = null,
    ): BudgetingReportSourceClose {
        return new BudgetingReportSourceClose(
            self::closeId(),
            $identity ?? self::closeIdentity(),
            [new BudgetingReportSourceWatermark('budget', new DateTimeImmutable('2026-01-31T00:00:00+00:00'), 'budget:1', 'budget-v1')],
            $formulaVersion ?? BudgetPlanFactCandidateContract::FORMULA_VERSION,
            ['budget' => ['version' => 'budget:1']],
            str_repeat('a', 64),
            1,
            new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
            BudgetingReportSourceCloseStatus::APPROVED,
            null,
        );
    }

    /** @return array<string, mixed> */
    private static function report(): array
    {
        return (new PlanFactCalculator)->calculate(
            new PlanFactReportFilters(
                1,
                '2026-01-01',
                '2026-01-31',
                1,
                'budget-1',
                1,
                'scenario-1',
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                PlanFactReportFilters::DEFAULT_GROUP_BY,
            ),
            [
                new PlanFactSourceAggregate('budget_amount', '2026-01', 1, 10, 10, null, 'RUB', 'outflow', 100.0),
                new PlanFactSourceAggregate('payment_transaction', '2026-01', 1, 10, 10, null, 'RUB', 'outflow', actualAmount: 160.0),
                new PlanFactSourceAggregate('budget_amount', '2026-01', 2, 20, 20, null, 'USD', 'income', 100.0),
                new PlanFactSourceAggregate('payment_transaction', '2026-01', 2, 20, 20, null, 'USD', 'income', actualAmount: 120.0),
            ],
            new PlanFactDimensions(
                [
                    1 => ['id' => 'article-outflow', 'code' => 'OPEX', 'flow_direction' => 'outflow'],
                    2 => ['id' => 'article-income', 'code' => 'INCOME', 'flow_direction' => 'income'],
                ],
                [
                    10 => ['id' => 'center-outflow', 'code' => 'C10'],
                    20 => ['id' => 'center-income', 'code' => 'C20'],
                ],
                [10 => ['id' => 10, 'name' => 'A'], 20 => ['id' => 20, 'name' => 'B']],
                [],
            ),
            ['id' => 'scenario-1'],
            ['id' => 'budget-1'],
            [
                ['source_type' => 'budget_amounts', 'included_aggregate_rows' => 1],
                ['source_type' => 'payment_transactions', 'included_aggregate_rows' => 1],
            ],
            [],
        )->toArray();
    }

    /** @return array<string, mixed> */
    private static function drill(string $sourceType, int $sourceId, float $amount, string $currency): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'date' => '2026-01-15',
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed',
            'variance_contribution' => $amount,
        ];
    }
}

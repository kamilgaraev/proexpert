<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementSourceFact;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementAccountingPolicy;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagementPnlSourceTest extends TestCase
{
    #[Test]
    public function direct_labor_is_classified_once_and_allocated_to_one_hundred_percent(): void
    {
        $fact = $this->laborFact();
        $policy = new ManagementAccountingPolicy(
            policyVersion: 'management-pnl-policy.v1',
            classifications: ['project_labor_cost.direct_labor' => 'direct_labor'],
            allocations: [
                'project_labor_cost.direct_labor' => [
                    [
                        'project_id' => 101,
                        'responsibility_center_id' => 201,
                        'budget_article_id' => 301,
                        'basis_points' => 6000,
                    ],
                    [
                        'project_id' => 102,
                        'responsibility_center_id' => 202,
                        'budget_article_id' => 302,
                        'basis_points' => 4000,
                    ],
                ],
            ],
        );

        self::assertSame('direct_labor', $policy->classify($fact)->category);
        self::assertSame('management-pnl-policy.v1', $policy->version());
        self::assertSame(10_000, array_sum(array_map(
            static fn ($allocation): int => $allocation->basisPoints,
            $policy->allocate($fact),
        )));
        self::assertSame(
            ['101:201:301', '102:202:302'],
            array_map(
                static fn ($allocation): string => implode(':', [
                    $allocation->projectId,
                    $allocation->responsibilityCenterId,
                    $allocation->budgetArticleId,
                ]),
                $policy->allocate($fact),
            ),
        );
    }

    #[Test]
    public function allocation_policy_with_less_than_one_hundred_percent_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        new ManagementAccountingPolicy(
            policyVersion: 'management-pnl-policy.invalid',
            classifications: ['project_labor_cost.direct_labor' => 'direct_labor'],
            allocations: [
                'project_labor_cost.direct_labor' => [
                    ['basis_points' => 9999],
                ],
            ],
        );
    }

    #[Test]
    public function source_identity_pins_snapshot_row_and_metric(): void
    {
        self::assertSame(
            "snapshot-1\0project_labor_cost\0labor-77\0direct_labor",
            $this->laborFact()->identity(),
        );
    }

    private function laborFact(): ManagementSourceFact
    {
        return new ManagementSourceFact(
            sourceSnapshotId: 'snapshot-1',
            sourceType: 'project_labor_cost',
            sourceRowKey: 'labor-77',
            metricCode: 'direct_labor',
            organizationId: 10,
            projectId: 101,
            responsibilityCenterId: 201,
            budgetArticleId: 301,
            period: '2026-07',
            scenario: 'base',
            currency: 'RUB',
            amountMinor: 20_000,
            sourceRefs: [['type' => 'approved_time_entry', 'id' => 77]],
        );
    }
}

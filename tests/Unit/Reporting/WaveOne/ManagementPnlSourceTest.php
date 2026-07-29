<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementSourceFact;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementAccountingPolicy;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlComponentSet;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlComponentSnapshot;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
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
    public function direct_labor_classification_is_reserved_for_the_labor_owner_source(): void
    {
        $policy = new ManagementAccountingPolicy(
            policyVersion: 'management-pnl-policy.v1',
            classifications: ['budget_plan_fact.actual_non_labor_cost' => 'direct_labor'],
            allocations: [],
        );
        $fact = new ManagementSourceFact(
            sourceSnapshotId: 'plan-fact-snapshot',
            sourceType: 'budget_plan_fact',
            sourceRowKey: 'plan-fact-1',
            metricCode: 'actual_non_labor_cost',
            organizationId: 10,
            projectId: 101,
            responsibilityCenterId: null,
            budgetArticleId: null,
            period: '2026-07-15',
            scenario: 'actual',
            currency: 'RUB',
            amountMinor: 100,
            sourceRefs: [['type' => 'budget_plan_fact', 'id' => 1]],
        );

        $this->expectException(DomainException::class);
        $policy->classify($fact);
    }

    #[Test]
    public function source_identity_pins_snapshot_row_and_metric(): void
    {
        self::assertSame(
            "snapshot-1\0project_labor_cost\0labor-77\0direct_labor",
            $this->laborFact()->identity(),
        );
    }

    #[Test]
    public function composite_requires_exactly_one_pinned_snapshot_per_component_and_currency(): void
    {
        $set = new ManagementPnlComponentSet();
        $components = [
            $this->component('project_margin'),
            $this->component('budget_plan_fact'),
            $this->component('project_labor_cost'),
            $this->component('payroll_readiness'),
        ];

        self::assertSame($components, $set->validate($components, 10, [101], '2026-07-01', '2026-07-31', 'base'));

        $this->expectException(DomainException::class);
        $set->validate([...$components, $this->component('project_margin')], 10, [101], '2026-07-01', '2026-07-31', 'base');
    }

    #[Test]
    public function composite_rejects_a_fact_outside_the_exact_scope(): void
    {
        $components = [
            $this->component('project_margin'),
            $this->component('budget_plan_fact'),
            $this->component('project_labor_cost', projectId: 999),
            $this->component('payroll_readiness'),
        ];

        $this->expectException(DomainException::class);
        (new ManagementPnlComponentSet())->validate(
            $components,
            10,
            [101],
            '2026-07-01',
            '2026-07-31',
            'base',
        );
    }

    private function component(string $code, int $projectId = 101): ManagementPnlComponentSnapshot
    {
        return new ManagementPnlComponentSnapshot(
            componentCode: $code,
            snapshotId: $code.'-snapshot',
            sourceHash: new Sha256Hash(str_repeat('a', 64)),
            formulaVersion: $code.'.v1',
            sourceSchemaVersion: $code.'_v1',
            periodFrom: '2026-07-01',
            periodTo: '2026-07-31',
            scenario: 'base',
            currency: 'RUB',
            facts: [
                new ManagementSourceFact(
                    sourceSnapshotId: $code.'-snapshot',
                    sourceType: $code,
                    sourceRowKey: $code.'-1',
                    metricCode: $code === 'project_labor_cost' ? 'direct_labor' : 'amount',
                    organizationId: 10,
                    projectId: $projectId,
                    responsibilityCenterId: null,
                    budgetArticleId: null,
                    period: '2026-07-15',
                    scenario: 'base',
                    currency: 'RUB',
                    amountMinor: 100,
                    sourceRefs: [['type' => $code, 'id' => 1]],
                ),
            ],
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

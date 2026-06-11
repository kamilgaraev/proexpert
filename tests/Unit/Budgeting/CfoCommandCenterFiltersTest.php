<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use App\BusinessModules\Features\Budgeting\Http\Requests\CfoCommandCenterRequest;
use App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CfoCommandCenterFiltersTest extends TestCase
{
    public function test_filters_build_calendar_and_plan_fact_inputs_without_losing_scope(): void
    {
        $filters = new CfoCommandCenterFilters(
            organizationId: 42,
            periodStart: '2026-06-09',
            periodEnd: '2026-07-12',
            projectId: 14,
            responsibilityCenterId: 88,
            responsibilityCenterUuid: 'center-uuid',
            budgetArticleId: 77,
            budgetArticleUuid: 'article-uuid',
            counterpartyId: 30,
            currency: 'RUB',
            budgetVersionUuid: 'version-uuid',
            scenarioUuid: 'scenario-uuid',
            itemLimit: 10,
        );

        $calendar = $filters->calendarFilters();
        $cashGap = $filters->cashGapFilters('RUB');
        $planFact = $filters->planFactInput();

        $this->assertSame(42, $calendar->organizationId);
        $this->assertSame('2026-06-09', $calendar->periodStart);
        $this->assertSame('2026-07-12', $calendar->periodEnd);
        $this->assertSame(14, $calendar->projectId);
        $this->assertSame(88, $calendar->responsibilityCenterId);
        $this->assertSame(77, $calendar->budgetArticleId);
        $this->assertSame('RUB', $calendar->currency);
        $this->assertSame('88', $cashGap->responsibilityCenterId);
        $this->assertSame('77', $cashGap->budgetArticleId);
        $this->assertSame('RUB', $cashGap->currency);
        $this->assertSame('2026-06-01', $filters->periodStartMonth());
        $this->assertSame('2026-07-01', $filters->periodEndMonth());
        $this->assertSame('center-uuid', $planFact['responsibility_center_id']);
        $this->assertSame('article-uuid', $planFact['budget_article_id']);
        $this->assertSame('version-uuid', $planFact['budget_version_uuid']);
        $this->assertSame('scenario-uuid', $planFact['scenario_uuid']);
        $this->assertSame(PlanFactReportFilters::DEFAULT_GROUP_BY, $planFact['group_by']);
        $this->assertSame('center-uuid', $filters->toArray()['responsibility_center_id']);
    }

    public function test_cfo_command_center_accepts_project_portfolio_filters(): void
    {
        $rules = (new CfoCommandCenterRequest())->rules();
        $filters = new CfoCommandCenterFilters(
            organizationId: 42,
            periodStart: '2026-06-01',
            periodEnd: '2026-06-30',
            projectManagerUserId: 31,
            projectStatus: 'active',
            projectType: 'commercial',
            costCategoryId: 9,
        );

        $this->assertArrayHasKey('project_manager_user_id', $rules);
        $this->assertArrayHasKey('project_status', $rules);
        $this->assertArrayHasKey('project_type', $rules);
        $this->assertArrayHasKey('cost_category_id', $rules);
        $this->assertSame(31, $filters->toArray()['project_manager_user_id']);
        $this->assertSame('active', $filters->toArray()['project_status']);
        $this->assertSame('commercial', $filters->toArray()['project_type']);
        $this->assertSame(9, $filters->toArray()['cost_category_id']);
    }

    public function test_cfo_command_center_accepts_full_budget_version_period(): void
    {
        $reflection = new ReflectionClass(CfoCommandCenterService::class);
        $method = $reflection->getMethod('resolveFilters');
        $filters = $method->invoke($reflection->newInstanceWithoutConstructor(), [
            'current_organization_id' => 42,
            'period_start' => '2026-01-01',
            'period_end' => '2027-01-31',
            'budget_version_uuid' => 'cac5f4d0-003c-457c-b6b8-c1de3a71e055',
            'scenario_uuid' => '489f9829-9a3f-4e39-813c-36cedc8c5b5e',
            'item_limit' => 10,
        ]);

        if (!$filters instanceof CfoCommandCenterFilters) {
            self::fail('CFO command center filters were not resolved.');
        }

        $this->assertSame('2026-01-01', $filters->periodStart);
        $this->assertSame('2027-01-31', $filters->periodEnd);
        $this->assertSame('cac5f4d0-003c-457c-b6b8-c1de3a71e055', $filters->budgetVersionUuid);
        $this->assertSame('489f9829-9a3f-4e39-813c-36cedc8c5b5e', $filters->scenarioUuid);
    }
}

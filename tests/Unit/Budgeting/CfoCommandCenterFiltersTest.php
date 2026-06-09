<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use PHPUnit\Framework\TestCase;

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
}

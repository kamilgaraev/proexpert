<?php

declare(strict_types=1);

namespace Tests\Feature\Budgeting;

use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetVersionService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

final class BudgetVersionSummaryPostgresTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_does_not_double_count_replaced_budget_versions(): void
    {
        $organization = Organization::factory()->create();
        $period = BudgetPeriod::create([
            'organization_id' => $organization->id,
            'code' => '2026-08',
            'name' => 'Август 2026',
            'period_type' => 'month',
            'starts_at' => '2026-08-01',
            'ends_at' => '2026-08-31',
            'status' => 'open',
        ]);
        $scenario = BudgetScenario::create([
            'organization_id' => $organization->id,
            'code' => 'BASE',
            'name' => 'Базовый',
            'scenario_type' => 'base',
            'is_default' => true,
            'is_active' => true,
        ]);
        $article = BudgetArticle::create([
            'organization_id' => $organization->id,
            'code' => 'MATERIALS',
            'name' => 'Материалы',
            'budget_kind' => 'bdds',
            'flow_direction' => 'outflow',
            'management_cost_class' => 'non_labor',
            'is_leaf' => true,
            'is_active' => true,
        ]);
        $center = ResponsibilityCenter::create([
            'organization_id' => $organization->id,
            'center_type' => 'department',
            'code' => 'SUPPLY',
            'name' => 'Снабжение',
            'is_active' => true,
        ]);

        foreach (['active', 'replaced'] as $index => $status) {
            $version = BudgetVersion::create([
                'organization_id' => $organization->id,
                'budget_period_id' => $period->id,
                'scenario_id' => $scenario->id,
                'budget_kind' => 'bdds',
                'version_number' => $index + 1,
                'name' => "БДДС версия {$index}",
                'status' => $status,
                'workflow_history' => [],
            ]);
            $line = BudgetLine::create([
                'budget_version_id' => $version->id,
                'budget_article_id' => $article->id,
                'responsibility_center_id' => $center->id,
                'currency' => 'RUB',
            ]);
            BudgetAmount::create([
                'budget_line_id' => $line->id,
                'month' => '2026-08-01',
                'plan_amount' => 100000,
                'forecast_amount' => 100000,
                'currency' => 'RUB',
            ]);
        }

        $summaryMethod = new ReflectionMethod(BudgetVersionService::class, 'summary');
        $summary = $summaryMethod->invoke(
            $this->app->make(BudgetVersionService::class),
            BudgetVersion::query()->where('organization_id', $organization->id),
        );

        self::assertSame(100000.0, $summary['plan_total']);
        self::assertSame(100000.0, $summary['forecast_total']);
        self::assertSame(1, $summary['active_versions']);
    }
}

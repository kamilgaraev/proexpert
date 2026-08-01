<?php

declare(strict_types=1);

namespace Tests\Feature\Budgeting;

use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\PlanFactCalculator;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportService;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PlanFactReportProjectScopePostgresTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        if (getenv('RUN_PLAN_FACT_POSTGRES_SCOPE_TESTS') !== '1') {
            self::markTestSkipped('Requires an explicit isolated PostgreSQL plan/fact scope environment.');
        }

        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires PostgreSQL.');
        }

        $database = (string) (DB::selectOne('SELECT current_database() AS name')->name ?? '');
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        if (!Schema::hasColumns('payment_documents', ['budget_article_id', 'responsibility_center_id', 'direction'])
            || !Schema::hasColumns('payment_transactions', ['payment_document_id', 'project_id', 'status'])) {
            self::markTestSkipped('Current PostgreSQL test schema does not contain the plan/fact payment source columns.');
        }

        DB::beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_project_scope_restricts_real_aggregates_and_drills_while_legacy_entrypoints_remain_organization_wide(): void
    {
        $fixture = $this->fixture();
        $service = new PlanFactReportService(new PlanFactCalculator());
        $filters = [
            'organization_id' => $fixture['organization']->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'budget_version_uuid' => $fixture['version']->uuid,
            'scenario_uuid' => $fixture['scenario']->uuid,
            'group_by' => ['currency'],
            '_skip_data_mart_meta' => true,
        ];

        $scoped = $service->reportForProjectScope($filters, [$fixture['firstProject']->id]);
        $legacy = $service->report($filters);
        $drillKey = $scoped['rows'][0]['drill_down_key'];
        $scopedDrill = $service->drillDownForProjectScope([...$filters, 'drill_down_key' => $drillKey], [$fixture['firstProject']->id]);
        $legacyDrill = $service->drillDown([...$filters, 'drill_down_key' => $drillKey]);
        $empty = $service->reportForProjectScope($filters, []);
        $emptyDrill = $service->drillDownForProjectScope([...$filters, 'drill_down_key' => $drillKey], []);

        self::assertSame(100.0, $scoped['rows'][0]['plan_amount']);
        self::assertSame(10.0, $scoped['rows'][0]['actual_amount']);
        self::assertSame(300.0, $legacy['rows'][0]['plan_amount']);
        self::assertSame(30.0, $legacy['rows'][0]['actual_amount']);
        self::assertSame([$fixture['firstTransactionId']], array_column($scopedDrill['items'], 'source_id'));
        self::assertSame([$fixture['firstTransactionId'], $fixture['secondTransactionId']], array_column($legacyDrill['items'], 'source_id'));
        self::assertSame([], $empty['rows']);
        self::assertSame([], $emptyDrill['items']);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $firstProject = Project::factory()->create(['organization_id' => $organization->id]);
        $secondProject = Project::factory()->create(['organization_id' => $organization->id]);
        $period = BudgetPeriod::query()->create([
            'organization_id' => $organization->id,
            'code' => 'PF-2026-01-'.uniqid(),
            'name' => 'Plan fact January',
            'period_type' => 'month',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-31',
            'status' => 'open',
        ]);
        $scenario = BudgetScenario::query()->create([
            'organization_id' => $organization->id,
            'code' => 'PF-BASE-'.uniqid(),
            'name' => 'Plan fact base',
            'scenario_type' => 'base',
            'is_default' => true,
            'is_active' => true,
        ]);
        $article = BudgetArticle::query()->create([
            'organization_id' => $organization->id,
            'code' => 'PF-ARTICLE-'.uniqid(),
            'name' => 'Plan fact article',
            'budget_kind' => 'bdds',
            'flow_direction' => 'outflow',
            'is_leaf' => true,
            'is_active' => true,
        ]);
        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $organization->id,
            'center_type' => 'project',
            'code' => 'PF-CENTER-'.uniqid(),
            'name' => 'Plan fact center',
            'is_active' => true,
        ]);
        $version = BudgetVersion::query()->create([
            'organization_id' => $organization->id,
            'budget_period_id' => $period->id,
            'scenario_id' => $scenario->id,
            'budget_kind' => 'bdds',
            'version_number' => 1,
            'name' => 'Plan fact active',
            'status' => 'active',
            'approved_at' => now(),
            'activated_at' => now(),
        ]);

        $this->budgetLine($version, $article, $center, $firstProject, 100.0);
        $this->budgetLine($version, $article, $center, $secondProject, 200.0);
        $firstTransactionId = $this->transaction($organization, $firstProject, $article, $center, 10.0);
        $secondTransactionId = $this->transaction($organization, $secondProject, $article, $center, 20.0);

        return compact('organization', 'firstProject', 'secondProject', 'scenario', 'version', 'firstTransactionId', 'secondTransactionId');
    }

    private function budgetLine(BudgetVersion $version, BudgetArticle $article, ResponsibilityCenter $center, Project $project, float $amount): void
    {
        $line = BudgetLine::query()->create([
            'budget_version_id' => $version->id,
            'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id,
            'project_id' => $project->id,
            'currency' => 'RUB',
        ]);
        BudgetAmount::query()->create([
            'budget_line_id' => $line->id,
            'month' => '2026-01-01',
            'plan_amount' => $amount,
            'forecast_amount' => $amount,
            'currency' => 'RUB',
        ]);
    }

    private function transaction(Organization $organization, Project $project, BudgetArticle $article, ResponsibilityCenter $center, float $amount): int
    {
        $now = now();
        $documentId = DB::table('payment_documents')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_type' => 'invoice',
            'document_number' => 'PF-'.uniqid(),
            'document_date' => '2026-01-15',
            'amount' => $amount,
            'currency' => 'RUB',
            'paid_amount' => $amount,
            'status' => 'completed',
            'direction' => 'outgoing',
            'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('payment_transactions')->insertGetId([
            'payment_document_id' => $documentId,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'amount' => $amount,
            'currency' => 'RUB',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'PF-TX-'.uniqid(),
            'transaction_date' => '2026-01-15',
            'value_date' => '2026-01-15',
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

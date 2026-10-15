<?php

declare(strict_types=1);

namespace Tests\Feature\Budgeting;

use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetLineService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BudgetLineProjectLabelsPostgresTest extends TestCase
{
    use RefreshDatabase;

    public static function accessModes(): array
    {
        return [['all_projects'], ['assigned_projects']];
    }

    #[DataProvider('accessModes')]
    public function test_labels_are_batched_and_preserve_actor_and_organization_scope(string $mode): void
    {
        [$user, $version, $article, $center] = $this->fixture($mode);
        $names = [];
        for ($index = 0; $index < 20; $index++) {
            $project = Project::factory()->create(['organization_id' => $version->organization_id, 'name' => "Проект {$index}", 'is_archived' => $index === 0]);
            $user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true, 'assigned_at' => now()]);
            $names[$project->id] = $project->name;
            $this->line($version, $article, $center, $project->id);
        }
        $unassigned = Project::factory()->create(['organization_id' => $version->organization_id, 'name' => 'Без назначения', 'is_archived' => false]);
        $foreign = Project::factory()->create(['name' => 'Чужая организация']);
        $this->line($version, $article, $center, $unassigned->id);
        $this->line($version, $article, $center, $foreign->id);

        DB::enableQueryLog();
        try {
            $rows = $this->app->make(BudgetLineService::class)->lines($user, $version->uuid, []);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $byProject = array_column($rows, null, 'project_id');
        foreach ($names as $id => $name) {
            self::assertSame($name, $byProject[$id]['project_name'] ?? null);
        }
        self::assertNull($byProject[$foreign->id]['project_name']);
        self::assertSame($mode === 'all_projects' ? $unassigned->name : null, $byProject[$unassigned->id]['project_name']);
        self::assertCount(1, array_filter($queries, static fn (array $query): bool => preg_match('/^select .* from "projects"/i', $query['query']) === 1));
        self::assertCount(22, $rows);
    }

    public function test_unscoped_lines_do_not_trigger_a_project_lookup(): void
    {
        [$user, $version, $article, $center] = $this->fixture('all_projects');
        $this->line($version, $article, $center, null);
        DB::enableQueryLog();
        try {
            $rows = $this->app->make(BudgetLineService::class)->lines($user, $version->uuid, []);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        self::assertArrayHasKey('project_name', $rows[0]);
        self::assertNull($rows[0]['project_name']);
        self::assertCount(0, array_filter($queries, static fn (array $query): bool => preg_match('/^select .* from "projects"/i', $query['query']) === 1));
    }

    public function test_replacement_returns_the_server_project_name_and_unchanged_amounts(): void
    {
        [$user, $version, $article, $center] = $this->fixture('all_projects');
        $project = Project::factory()->create(['organization_id' => $version->organization_id, 'name' => 'Учебный ПТО', 'is_archived' => false]);
        $rows = $this->app->make(BudgetLineService::class)->replace($user, $version->uuid, [[
            'budget_article_id' => $article->uuid,
            'responsibility_center_id' => $center->uuid,
            'project_id' => $project->id,
            'project_name' => 'Подставленное имя',
            'currency' => 'RUB',
            'amounts' => [['month' => '2026-10', 'plan' => 100000, 'forecast' => 90000]],
        ]]);
        self::assertSame('Учебный ПТО', $rows[0]['project_name'] ?? null);
        self::assertSame($project->id, $rows[0]['project_id']);
        self::assertSame([['month' => '2026-10', 'plan' => 100000.0, 'forecast' => 90000.0]], $rows[0]['amounts']);
    }

    private function fixture(string $mode): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $user->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => $mode]);
        $period = BudgetPeriod::create(['organization_id' => $organization->id, 'code' => '2026-10', 'name' => 'Октябрь', 'period_type' => 'month', 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'open']);
        $scenario = BudgetScenario::create(['organization_id' => $organization->id, 'code' => 'BASE', 'name' => 'Базовый', 'scenario_type' => 'base', 'is_default' => true, 'is_active' => true]);
        $article = BudgetArticle::create(['organization_id' => $organization->id, 'code' => 'WORKS', 'name' => 'Отделка', 'budget_kind' => 'bdds', 'flow_direction' => 'outflow', 'management_cost_class' => 'labor', 'is_leaf' => true, 'is_active' => true]);
        $center = ResponsibilityCenter::create(['organization_id' => $organization->id, 'code' => 'PTO', 'name' => 'ПТО', 'center_type' => 'project', 'is_active' => true]);
        $version = BudgetVersion::create(['organization_id' => $organization->id, 'budget_period_id' => $period->id, 'scenario_id' => $scenario->id, 'budget_kind' => 'bdds', 'version_number' => 1, 'name' => 'Бюджет ПТО', 'status' => 'draft', 'workflow_history' => []]);

        return [$user, $version, $article, $center];
    }

    private function line(BudgetVersion $version, BudgetArticle $article, ResponsibilityCenter $center, ?int $projectId): void
    {
        BudgetLine::create(['budget_version_id' => $version->id, 'budget_article_id' => $article->id, 'responsibility_center_id' => $center->id, 'project_id' => $projectId, 'currency' => 'RUB']);
    }
}

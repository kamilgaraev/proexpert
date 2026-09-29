<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateEvidenceService;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateResolver;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialAnswerService;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimatePositionsTool;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Module;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

final class AssistantFinancialEvidenceTest extends TestCase
{
    private User $actor;
    private Organization $organization;
    private Project $project;
    private array $denied = [];
    private bool $financeAllowed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]);
        $this->actor = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $authorization = $this->mock(AuthorizationService::class);
        $can = fn (User $actor, string $permission): bool => $permission !== 'budget-estimates.finance.view' || $this->financeAllowed;
        $authorization->shouldReceive('can')->andReturnUsing($can);
        $authorization->shouldReceive('canCurrent')->andReturnUsing($can);
        $this->mock(OrganizationEntitlementService::class)->shouldReceive('getEffectiveModules')
            ->andReturn(collect([new Module(['slug' => 'budget-estimates']), new Module(['slug' => 'project-management'])]));
        $this->mock(UserProjectAccessService::class)->shouldReceive('queryAccessibleProjects')
            ->andReturnUsing(fn (User $actor, int $org) => Project::query()->where('organization_id', $org)
                ->whereNotIn('id', Estimate::query()->whereIn('id', $this->denied)->select('project_id')));
        $this->app->forgetInstance(AssistantDataAccessPolicy::class);
    }

    public function test_amount_followups_keep_estimate_and_explicit_name_switch_replaces_it(): void
    {
        $first = $this->estimate('A-100', 'Фундамент');
        $second = $this->estimate('A-200', 'Кровля');
        $this->item($first, '1', 'Бетон', '0.12345678', '123.45');
        $this->item($second, '1', 'Покрытие', '10', '900');
        $answers = app(AssistantFinancialAnswerService::class);
        $initial = $answers->answer('Смета №A-100: какая сумма?', $this->organization->id, $this->actor);
        self::assertSame($first->id, $initial['pinned_estimate_id']);
        $followup = $answers->answer('Какие конкретно позиции и деньги?', $this->organization->id, $this->actor, $initial['pinned_estimate_id']);
        self::assertSame($first->id, $followup['pinned_estimate_id']);
        self::assertStringContainsString('123.45', $followup['text']);
        self::assertStringContainsString('0.12345678', $followup['text']);
        self::assertStringNotContainsString('900.00', $followup['text']);
        $switch = $answers->answer('Теперь смета «Кровля», какие позиции?', $this->organization->id, $this->actor, $first->id);
        self::assertSame($second->id, $switch['pinned_estimate_id']);
        self::assertStringContainsString('900.00', $switch['text']);
        $bareName = $answers->answer('Деньги по смете Фундамент', $this->organization->id, $this->actor, $second->id);
        self::assertSame($first->id, $bareName['pinned_estimate_id']);
        self::assertFalse($answers->isApplicable('Какой сегодня день?', $first->id));
        self::assertFalse($answers->isApplicable('Какие договоры доступны?', $first->id));
        self::assertFalse($answers->isApplicable('Какие сотрудники работают?', $first->id));
        self::assertTrue($answers->isApplicable('SM-2026'));
    }

    public function test_ambiguous_selector_lists_only_accessible_same_organization_options(): void
    {
        $first = $this->estimate('DUP-1', 'Общая смета');
        $second = $this->estimate('DUP-2', 'Общая смета');
        $hidden = $this->estimate('DUP-3', 'Общая смета');
        $hiddenProject = Project::factory()->create(['organization_id' => $this->organization->id]);
        $hidden->update(['project_id' => $hiddenProject->id]);
        $this->denied = [$hidden->id];
        $foreignOrg = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrg->id]);
        Estimate::query()->create(['organization_id' => $foreignOrg->id, 'project_id' => $foreignProject->id,
            'number' => 'DUP-4', 'name' => 'Общая смета', 'estimate_date' => '2026-09-29']);
        $resolution = app(AssistantEstimateResolver::class)->resolve('смета «Общая смета»', $this->organization->id, $this->actor, $first->id);
        self::assertSame('ambiguous', $resolution['status']);
        self::assertNull($resolution['estimate_id']);
        self::assertSame([$first->id, $second->id], array_column($resolution['options'], 'id'));
    }

    public function test_snapshot_is_fresh_exact_and_does_not_double_count_children_or_excluded_parents(): void
    {
        $estimate = $this->estimate('LIVE', 'Текущая');
        $estimate->update(['status' => 'draft']);
        $parent = $this->item($estimate, '1', 'Работа', '2.12345678', '100.01', ['direct_costs' => '75.12']);
        $this->item($estimate, '1.1', 'Ресурс', '1', '30', ['parent_work_id' => $parent->id]);
        $excluded = $this->item($estimate, '2', 'Не учитываемая', '1', '300', ['is_not_accounted' => true]);
        $child = $this->item($estimate, '2.1', 'Её ресурс', '1', '200', ['parent_work_id' => $excluded->id]);
        $estimate->update(['total_amount' => '100.01']);
        $service = app(AssistantEstimateEvidenceService::class);
        $first = $service->snapshot($estimate->id, $this->organization->id, $this->actor);
        self::assertSame('LIVE', $first['estimate']['number']);
        self::assertSame('Текущая', $first['estimate']['name']);
        self::assertSame('draft', $first['estimate']['status']);
        self::assertSame('2026-09-29', $first['estimate']['estimate_date']);
        self::assertSame(['number', 'name', 'status', 'estimate_date', 'total_amount'], $first['source_refs'][0]['checked_fields']);
        self::assertStringContainsString('Дата сметы: 2026-09-29. Статус: Черновик.', app(AssistantFinancialAnswerService::class)->format('', $first));
        self::assertSame('100.01', $first['totals']['total_amount']);
        self::assertSame('75.1200', $first['totals']['direct_costs']);
        self::assertSame('verified', $first['validation_status']);
        $rows = array_column($first['positions'], null, 'id');
        self::assertTrue($rows[$child->id]['excluded']);
        self::assertFalse($rows[$child->id]['included_in_total']);
        self::assertSame('2.12345678', $rows[$parent->id]['quantity']);
        $parent->update(['total_amount' => '101.02']);
        $estimate->update(['status' => 'approved', 'estimate_date' => '2026-09-30']);
        $second = $service->snapshot($estimate->id, $this->organization->id, $this->actor);
        self::assertSame('approved', $second['estimate']['status']);
        self::assertSame('2026-09-30', $second['estimate']['estimate_date']);
        self::assertSame('101.02', $second['totals']['total_amount']);
        self::assertSame('partial', $second['validation_status']);
        self::assertNotSame($first['version'], $second['version']);
        self::assertCount(1, $second['source_refs']);
        foreach ($second['source_refs'] as $source) {
            self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadSource($this->actor, $this->organization->id, $source));
        }
    }

    public function test_revoked_pinned_estimate_produces_no_financial_sources(): void
    {
        $estimate = $this->estimate('REVOKED', 'Отозванная');
        $this->item($estimate, '1', 'Закрытая позиция', '1', '123');
        $this->denied = [$estimate->id];
        $answer = app(AssistantFinancialAnswerService::class)->answer('Какая сумма?', $this->organization->id, $this->actor, $estimate->id);
        self::assertNull($answer['pinned_estimate_id']);
        self::assertSame([], $answer['source_refs']);
        self::assertStringNotContainsString('123', $answer['text']);
        $this->expectException(AuthorizationException::class);
        app(AssistantEstimateEvidenceService::class)->snapshot($estimate->id, $this->organization->id, $this->actor);
    }

    public function test_concrete_material_filter_survives_money_followup_and_clears_on_switch(): void
    {
        $estimate = $this->estimate('CONCRETE', 'Материалы');
        $this->item($estimate, '1', 'Бетон В25', '3.12345678', '111.11');
        $this->item($estimate, '2', 'Кирпич', '5', '222.22');
        $answers = app(AssistantFinancialAnswerService::class);
        $first = $answers->answer('Смета №CONCRETE, сколько бетона?', $this->organization->id, $this->actor);
        self::assertSame(['бетон'], $first['selection']['position_filter']);
        self::assertStringContainsString('111.11', $first['text']);
        self::assertStringNotContainsString('Кирпич', $first['text']);
        $next = $answers->answer('А конкретно деньги?', $this->organization->id, $this->actor, $estimate->id, $first['selection']);
        self::assertSame(['бетон'], $next['selection']['position_filter']);
        self::assertStringNotContainsString('Кирпич', $next['text']);
        $all = $answers->answer('Все позиции', $this->organization->id, $this->actor, $estimate->id, $next['selection']);
        self::assertSame([], $all['selection']['position_filter']);
        self::assertStringContainsString('Кирпич', $all['text']);
        $missing = $answers->answer('Позиции по газобетону', $this->organization->id, $this->actor, $estimate->id);
        self::assertSame('partial', $missing['validation_status']);
        self::assertStringContainsString(trans_message('ai_assistant_financial.positions_not_found'), $missing['text']);
        $position = $answers->answer('Позиция №2', $this->organization->id, $this->actor, $estimate->id);
        $money = $answers->answer('А какая стоимость?', $this->organization->id, $this->actor, $estimate->id, $position['selection']);
        self::assertSame(['2'], $money['selection']['position_numbers']);
        self::assertStringContainsString('Кирпич', $money['text']);
        self::assertStringNotContainsString('Бетон В25', $money['text']);
    }

    public function test_accessible_project_estimate_requires_current_financial_permission(): void
    {
        $estimate = $this->estimate('CURRENT-FINANCE', 'Текущие финансовые права');
        $this->item($estimate, '1', 'Организационная работа', '2.12345678', '321.09');
        $service = app(AssistantEstimateEvidenceService::class);
        $snapshot = $service->snapshot($estimate->id, $this->organization->id, $this->actor);
        self::assertSame($this->project->id, $snapshot['estimate']['project_id']);
        self::assertSame('321.09', $snapshot['totals']['total_amount']);
        self::assertSame($this->project->id, $snapshot['source_refs'][0]['project_id']);
        $this->financeAllowed = false;
        $answer = app(AssistantFinancialAnswerService::class)->answer('Какая сумма?', $this->organization->id, $this->actor, $estimate->id);
        self::assertSame([], $answer['source_refs']);
        self::assertStringNotContainsString('321.09', $answer['text']);
        $this->expectException(AuthorizationException::class);
        $service->snapshot($estimate->id, $this->organization->id, $this->actor);
    }

    public function test_full_totals_and_versions_include_unshown_rows_while_public_references_are_bounded(): void
    {
        $estimate = $this->estimate('FULL-70', 'Полная смета');
        $items = [];
        for ($index = 1; $index <= 70; $index++) {
            $items[] = $this->item($estimate, (string) $index, 'Работа '.$index, '1', '1.00', ['direct_costs' => '0', 'overhead_amount' => '0', 'profit_amount' => '0', 'equipment_cost' => '0']);
        }
        $estimate->update(['total_amount' => '70.00']);
        $service = app(AssistantEstimateEvidenceService::class);
        $first = $service->snapshot($estimate->id, $this->organization->id, $this->actor);
        self::assertCount(70, $first['positions']);
        self::assertSame('70.00', $first['totals']['total_amount']);
        self::assertSame('verified', $first['validation_status']);
        self::assertCount(1, $first['source_refs']);
        $answer = app(AssistantFinancialAnswerService::class)->answer('Все позиции', $this->organization->id, $this->actor, $estimate->id);
        self::assertCount(51, $answer['source_refs']);
        self::assertSame(array_column(array_slice($first['positions'], 0, 50), 'id'), array_column(array_slice($answer['source_refs'], 1), 'entity_id'));
        self::assertStringNotContainsString('Работа 70', $answer['text']);
        $page = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $estimate->id, 'page' => 2, 'per_page' => 10], $this->actor, $this->organization);
        self::assertIsArray($page);
        self::assertSame(70, $page['meta']['total']);
        self::assertCount(11, $page['source_refs']);
        self::assertSame(array_column($page['positions'], 'id'), array_column(array_slice($page['source_refs'], 1), 'entity_id'));
        $largePage = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $estimate->id, 'page' => 1, 'per_page' => 100], $this->actor, $this->organization);
        self::assertIsArray($largePage);
        self::assertCount(70, $largePage['positions']);
        self::assertCount(51, $largePage['source_refs']);
        self::assertSame(array_column(array_slice($largePage['positions'], 0, 50), 'id'), array_column(array_slice($largePage['source_refs'], 1), 'entity_id'));
        $items[69]->update(['total_amount' => '9.00']);
        $second = $service->snapshot($estimate->id, $this->organization->id, $this->actor);
        self::assertSame('78.00', $second['totals']['total_amount']);
        self::assertSame('partial', $second['validation_status']);
        self::assertNotSame($first['version'], $second['version']);
        self::assertSame($first['positions'][0]['version'], $second['positions'][0]['version']);
        self::assertNotSame($first['positions'][69]['version'], $second['positions'][69]['version']);
        $estimate->update(['total_amount' => '78.00']);
        self::assertSame('verified', $service->snapshot($estimate->id, $this->organization->id, $this->actor)['validation_status']);
    }

    private function estimate(string $number, string $name): Estimate
    {
        return Estimate::query()->create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'number' => $number, 'name' => $name, 'estimate_date' => '2026-09-29']);
    }

    private function item(Estimate $estimate, string $number, string $name, string $quantity, string $amount, array $attributes = []): EstimateItem
    {
        return EstimateItem::query()->create($attributes + ['estimate_id' => $estimate->id, 'position_number' => $number,
            'name' => $name, 'item_type' => 'work', 'quantity' => $quantity, 'quantity_total' => null,
            'total_amount' => $amount, 'unit_price' => '1', 'is_manual' => true]);
    }
}

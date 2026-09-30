<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimatePositionReadService;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimatePositionsTool;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\MeasurementUnit;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantEstimatePositionReadPostgresTest extends TestCase
{
    private User $actor;

    private Organization $organization;

    private Project $project;

    private array $deniedEstimateIds = [];

    private bool $financeAllowed = true;

    private array $financeDeniedProjectIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]);
        $this->actor = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);

        $this->mock(AuthorizationService::class)
            ->shouldReceive('can')->andReturnUsing(fn (User $actor, string $permission, ?array $context = null): bool => $this->permission($permission, $context))
            ->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission, ?array $context = null): bool => $this->permission($permission, $context))
            ->shouldReceive('forCurrentChecks')->andReturnSelf();
        $this->mock(OrganizationEntitlementService::class)->shouldReceive('getEffectiveModules')
            ->andReturn(collect([new Module(['slug' => 'ai-assistant']), new Module(['slug' => 'budget-estimates']), new Module(['slug' => 'project-management'])]));
        $this->mock(UserProjectAccessService::class)->shouldReceive('queryAccessibleProjects')
            ->andReturnUsing(fn (User $actor, int $organizationId) => Project::query()->where('organization_id', $organizationId)
                ->whereNotIn('id', Estimate::query()->whereIn('id', $this->deniedEstimateIds)->select('project_id')));
        $this->app->forgetInstance(AssistantDataAccessPolicy::class);
    }

    public function test_selector_search_hydrates_only_page_and_returns_bounded_resource_composition(): void
    {
        $estimate = $this->estimate('SM-2026-0009', 'Перегородки');
        $now = now();
        $rows = [];
        for ($index = 1; $index <= 2349; $index++) {
            $rows[] = [
                'estimate_id' => $estimate->id,
                'position_number' => (string) $index,
                'name' => $index === 1724 ? 'Кладка перегородок' : 'Другая позиция '.$index,
                'item_type' => 'work',
                'quantity' => '1.0000',
                'quantity_total' => '1.0000',
                'unit_price' => '1.00',
                'direct_costs' => '1.00',
                'overhead_amount' => '0.00',
                'profit_amount' => '0.00',
                'total_amount' => '1.00',
                'is_manual' => true,
                'is_not_accounted' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            EstimateItem::query()->insert($chunk);
        }
        $item = EstimateItem::query()->where('estimate_id', $estimate->id)->where('position_number', '1724')->firstOrFail();
        $unit = MeasurementUnit::query()->firstOrCreate(
            ['organization_id' => $this->organization->id, 'short_name' => 'кг'],
            ['name' => 'Килограмм', 'type' => 'material', 'is_default' => false, 'is_system' => false],
        );
        for ($index = 1; $index <= 21; $index++) {
            EstimateItemResource::query()->create([
                'estimate_item_id' => $item->id,
                'resource_type' => $index === 1 ? 'material' : 'labor',
                'name' => $index === 1 ? 'Смесь кладочная' : 'Труд '.$index,
                'measurement_unit_id' => $unit->id,
                'quantity_per_unit' => '0.2500',
                'total_quantity' => '4.1250',
                'unit_price' => '125.50',
                'total_amount' => '517.69',
            ]);
        }

        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.EstimateItem::class, function (EstimateItem $retrieved) use (&$hydrated, $estimate): void {
            if ((int) $retrieved->estimate_id === (int) $estimate->id) {
                $hydrated++;
            }
        });
        self::assertTrue(app(AIPermissionChecker::class)->canExecuteTool($this->actor, 'get_estimate_positions', [
            'estimate_selector' => 'SM-2026-0009',
        ]));

        $result = app(GetEstimatePositionsTool::class)->execute([
            'estimate_selector' => 'SM-2026-0009',
            'query' => 'кладку перегородок',
            'per_page' => 20,
            'include_composition' => true,
            'composition_per_page' => 20,
        ], $this->actor, $this->organization);

        self::assertIsArray($result);
        self::assertSame('SM-2026-0009', $result['estimate']['number']);
        self::assertSame(2349, $result['meta']['total_estimate_positions']);
        self::assertSame(1, $result['meta']['total']);
        self::assertCount(1, $result['positions']);
        self::assertSame('Кладка перегородок', $result['positions'][0]['name']);
        self::assertSame('partial', $result['validation_status']);
        self::assertSame('returned_positions_and_resources', $result['validation_scope']);
        self::assertSame('resources', $result['composition']['scope']);
        self::assertCount(20, $result['composition']['items']);
        self::assertSame(21, $result['composition']['total']);
        self::assertTrue($result['composition']['has_more']);
        self::assertSame(2, $result['composition']['next_page']);
        self::assertSame('кг', $result['composition']['items'][0]['material_unit']);
        self::assertSame('4.1250', $result['composition']['items'][0]['total_quantity']);
        self::assertSame('125.50', $result['composition']['items'][0]['unit_price']);
        self::assertSame('517.69', $result['composition']['items'][0]['total_amount']);
        self::assertTrue((new AssistantStructuredFactVerifier)->trustedEvidence($result['structured_fact_evidence']));
        $guarded = (new AssistantStructuredFactVerifier)->guard('Покажи состав позиции', 'Непроверенное описание', [$result]);
        self::assertFalse($guarded['needs_clarification']);
        self::assertStringContainsString('Смесь кладочная', $guarded['text']);
        self::assertStringContainsString('4.1250', $guarded['text']);
        self::assertStringContainsString('517.69', $guarded['text']);
        self::assertLessThanOrEqual(1, $hydrated);
        self::assertCount(1, array_filter($result['source_refs'], static fn (array $source): bool => ($source['entity_type'] ?? null) === 'estimate_item'));
        self::assertCount(20, array_filter($result['source_refs'], static fn (array $source): bool => ($source['entity_type'] ?? null) === 'estimate_item_resource'));
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadSource($this->actor, $this->organization->id,
            array_values(array_filter($result['source_refs'], static fn (array $source): bool => ($source['entity_type'] ?? null) === 'estimate_item_resource'))[0]));

        $next = app(GetEstimatePositionsTool::class)->execute([
            'estimate_id' => $estimate->id,
            'page' => 2,
            'per_page' => 10,
        ], $this->actor, $this->organization);
        self::assertSame(2349, $next['meta']['total']);
        self::assertTrue($next['meta']['has_more']);
        self::assertSame(3, $next['meta']['next_page']);
        self::assertCount(10, $next['positions']);
        self::assertSame('11', $next['positions'][0]['position_number']);
        self::assertSame('20', $next['positions'][9]['position_number']);
        self::assertArrayNotHasKey('composition', $next);
    }

    public function test_resource_continuation_and_position_exclusion_are_scoped_to_the_same_estimate(): void
    {
        $estimate = $this->estimate('SM-LOCAL', 'Локальная смета');
        $foreignOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = Estimate::query()->create(['organization_id' => $foreignOrganization->id, 'project_id' => $foreignProject->id,
            'number' => 'SM-LOCAL', 'name' => 'Иная организация', 'estimate_date' => '2026-09-29']);
        $excluded = $this->item($estimate, '1', 'Исключённая работа', ['is_not_accounted' => true]);
        $target = $this->item($estimate, '1.1', 'Кладка перегородок', ['parent_work_id' => $excluded->id]);
        $foreignItem = $this->item($foreignEstimate, '1', 'Кладка перегородок');
        $unit = MeasurementUnit::query()->firstOrCreate(
            ['organization_id' => $this->organization->id, 'short_name' => 'м'],
            ['name' => 'Метр', 'type' => 'material', 'is_default' => false, 'is_system' => false],
        );
        for ($index = 1; $index <= 21; $index++) {
            EstimateItemResource::query()->create(['estimate_item_id' => $target->id, 'resource_type' => 'material', 'name' => 'Ресурс '.$index,
                'measurement_unit_id' => $unit->id, 'quantity_per_unit' => '1.0000', 'total_quantity' => '2.0000', 'unit_price' => '5.00', 'total_amount' => '10.00']);
        }
        $foreignUnit = MeasurementUnit::query()->create(['organization_id' => $foreignOrganization->id,
            'name' => 'Чужая единица', 'short_name' => 'чужая-ед', 'type' => 'material', 'is_default' => false, 'is_system' => false]);
        $target->update(['measurement_unit_id' => $foreignUnit->id]);
        EstimateItemResource::query()->where('estimate_item_id', $target->id)->where('name', 'Ресурс 21')
            ->update(['measurement_unit_id' => $foreignUnit->id]);

        $first = app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-LOCAL', 'query' => 'кладку перегородок',
            'include_composition' => true], $this->actor, $this->organization);
        self::assertIsArray($first);
        self::assertSame('SM-LOCAL', $first['estimate']['number']);
        self::assertSame([(int) $target->id], array_column($first['positions'], 'id'));
        self::assertTrue($first['positions'][0]['excluded']);
        self::assertNull($first['positions'][0]['unit']);
        self::assertSame(2, $first['composition']['next_page']);

        $second = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $estimate->id, 'position_id' => $target->id,
            'include_composition' => true, 'composition_page' => 2], $this->actor, $this->organization);
        self::assertIsArray($second);
        self::assertCount(1, $second['composition']['items']);
        self::assertSame('Ресурс 21', $second['composition']['items'][0]['name']);
        self::assertNull($second['composition']['items'][0]['material_unit']);
        self::assertStringNotContainsString('чужая-ед', json_encode($second, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertFalse($second['composition']['has_more']);

        $wrongPosition = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $estimate->id,
            'position_id' => $foreignItem->id, 'include_composition' => true], $this->actor, $this->organization);
        self::assertIsArray($wrongPosition);
        self::assertSame([], $wrongPosition['positions']);
        self::assertSame([], $wrongPosition['composition']['items']);

        $this->expectException(AuthorizationException::class);
        app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $foreignEstimate->id], $this->actor, $this->organization);
    }

    public function test_denied_estimate_selector_stops_before_positions_or_composition_are_read(): void
    {
        $estimate = $this->estimate('SM-DENIED', 'Закрытая смета');
        $item = $this->item($estimate, '1', 'Кладка перегородок');
        $this->deniedEstimateIds = [$estimate->id];

        $result = app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-DENIED', 'query' => 'кладку перегородок',
            'include_composition' => true], $this->actor, $this->organization);
        self::assertIsArray($result);
        self::assertSame('not_found', $result['status']);
        self::assertSame([], $result['positions']);
        self::assertSame([], $result['source_refs']);
        self::assertFalse(app(AssistantDataAccessPolicy::class)->canReadEntityContent($this->actor, $this->organization->id, 'estimate', $estimate->id));
        self::assertGreaterThan(0, (int) $item->id);
    }

    public function test_selector_requires_current_financial_permission(): void
    {
        $estimate = $this->estimate('SM-FINANCE', 'Финансовая смета');
        $this->item($estimate, '1', 'Работа');
        $this->financeAllowed = false;

        self::assertFalse(app(AIPermissionChecker::class)->canExecuteTool($this->actor, 'get_estimate_positions', [
            'estimate_selector' => 'SM-FINANCE',
        ]));
        self::assertFalse(app(AIPermissionChecker::class)->canExecuteTool($this->actor, 'get_estimate_positions', [
            'estimate_id' => $estimate->id,
        ]));

        $this->expectException(AuthorizationException::class);
        app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-FINANCE'], $this->actor, $this->organization);
    }

    public function test_resolved_selector_requires_project_financial_permission_before_reading_positions(): void
    {
        $estimate = $this->estimate('SM-PROJECT-FINANCE', 'Закрытая финансовая смета проекта');
        $this->item($estimate, '1', 'Работа');
        $this->financeDeniedProjectIds = [$this->project->id];
        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.EstimateItem::class, function () use (&$hydrated): void {
            $hydrated++;
        });
        try {
            app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-PROJECT-FINANCE',
                'include_composition' => true], $this->actor, $this->organization);
            self::fail('Project financial access denied');
        } catch (AuthorizationException) {
            self::assertSame(0, $hydrated);
        }
    }

    public function test_selector_filters_ambiguous_options_by_project_financial_permission(): void
    {
        $allowed = $this->estimate('SM-DUP-A', 'Доступная смета');
        $deniedProject = Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]);
        $this->estimate('SM-DUP-B', 'Закрытая финансовая смета', project: $deniedProject);
        $this->financeDeniedProjectIds = [$deniedProject->id];

        $result = app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-DUP'], $this->actor, $this->organization);
        self::assertIsArray($result);
        self::assertSame($allowed->id, $result['estimate']['id']);
        self::assertStringNotContainsString('Закрытая финансовая смета', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $this->financeDeniedProjectIds[] = $this->project->id;
        $denied = app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-DUP'], $this->actor, $this->organization);
        self::assertIsArray($denied);
        self::assertSame('not_found', $denied['status']);
        self::assertSame([], $denied['resolution']['options']);
        self::assertSame([], $denied['source_refs']);
    }

    public function test_multiple_positions_require_selection_and_adjacent_rows_are_not_resources(): void
    {
        $estimate = $this->estimate('SM-MULTIPLE', 'Отделка');
        $target = $this->item($estimate, '1', 'Облицовка стены', ['normative_rate_code' => 'NORM-12-03']);
        $this->item($estimate, '2', 'Облицовка колонны');
        $this->item($estimate, '3', 'Соседний материал', ['item_type' => 'material']);

        $ambiguous = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $estimate->id, 'query' => 'облицовка',
            'per_page' => 1, 'include_composition' => true], $this->actor, $this->organization);
        self::assertIsArray($ambiguous);
        self::assertSame(2, $ambiguous['meta']['total']);
        self::assertTrue($ambiguous['meta']['has_more']);
        self::assertSame('select_one_position', $ambiguous['composition']['status']);
        self::assertSame([], $ambiguous['composition']['items']);

        $selected = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $estimate->id, 'position_id' => $target->id,
            'include_composition' => true], $this->actor, $this->organization);
        self::assertIsArray($selected);
        self::assertSame('resources', $selected['composition']['scope']);
        self::assertSame(0, $selected['composition']['total']);
        self::assertSame([], $selected['composition']['items']);
        self::assertFalse($selected['composition']['has_more']);
        self::assertSame(0, $selected['structured_fact_evidence']['composition_page']['total']);
        self::assertTrue((new AssistantStructuredFactVerifier)->trustedEvidence($selected['structured_fact_evidence']));

        $byCode = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $estimate->id, 'query' => 'NORM-12-03'], $this->actor, $this->organization);
        self::assertIsArray($byCode);
        self::assertSame([(int) $target->id], array_column($byCode['positions'], 'id'));
        self::assertSame('NORM-12-03', $byCode['positions'][0]['normative_rate_code']);
    }

    public function test_reader_rejects_unbounded_direct_calls(): void
    {
        $estimate = $this->estimate('SM-BOUNDS', 'Границы страницы');
        $reader = app(AssistantEstimatePositionReadService::class);
        foreach ([['page' => 0], ['perPage' => 101], ['compositionPerPage' => 21], ['compositionPage' => 0],
            ['positionId' => 0], ['query' => str_repeat('я', 201)]] as $arguments) {
            try {
                $reader->page($estimate->id, $this->organization->id, $this->actor, ...$arguments);
                self::fail('Unbounded read accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey(array_key_first($arguments), $exception->errors());
            }
        }
    }

    public function test_reader_rechecks_membership_inside_an_existing_authorization_scope(): void
    {
        $estimate = $this->estimate('SM-REVOKED', 'Отозванный доступ');
        $access = app(AssistantDataAccessPolicy::class);
        $this->expectException(AuthorizationException::class);
        $access->withCurrentChecks($this->actor, $this->organization->id, function () use ($access, $estimate): void {
            self::assertTrue($access->canReadEntityContent($this->actor, $this->organization->id, 'estimate', $estimate->id));
            $this->actor->organizations()->updateExistingPivot($this->organization->id, ['is_active' => false]);
            app(AssistantEstimatePositionReadService::class)->page($estimate->id, $this->organization->id, $this->actor);
        });
    }

    public function test_selector_rechecks_finance_before_returning_ambiguous_options(): void
    {
        $this->estimate('SM-REVOKED-FINANCE-A', 'Первая смета');
        $this->estimate('SM-REVOKED-FINANCE-B', 'Вторая смета');
        $access = app(AssistantDataAccessPolicy::class);
        $this->expectException(AuthorizationException::class);
        $access->withCurrentChecks($this->actor, $this->organization->id, function () use ($access): void {
            self::assertTrue($access->canCurrentPermission($this->actor, $this->organization->id, 'budget-estimates.finance.view'));
            $this->financeAllowed = false;
            app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-REVOKED-FINANCE'], $this->actor, $this->organization);
        });
    }

    public function test_review_selector_keeps_ambiguity_when_allowed_estimates_follow_the_first_twenty_options(): void
    {
        $deniedProject = Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]);
        for ($index = 1; $index <= 19; $index++) {
            $this->estimate('SM-WINDOW-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'Закрытая смета '.$index, project: $deniedProject);
        }
        $first = $this->estimate('SM-WINDOW-20', 'Первая доступная смета');
        $second = $this->estimate('SM-WINDOW-21', 'Вторая доступная смета');
        $this->financeDeniedProjectIds = [$deniedProject->id];

        $result = app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-WINDOW'], $this->actor, $this->organization);
        self::assertIsArray($result);
        self::assertSame('ambiguous', $result['status']);
        self::assertSame(2, $result['meta']['total']);
        self::assertSame([$first->id, $second->id], array_column($result['resolution']['options'], 'id'));
        self::assertSame([], $result['positions']);
        self::assertSame([], $result['source_refs']);

        $this->financeDeniedProjectIds = [];
        $all = app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-WINDOW'], $this->actor, $this->organization);
        self::assertIsArray($all);
        self::assertSame('ambiguous', $all['status']);
        self::assertSame(21, $all['meta']['total']);
        self::assertCount(20, $all['resolution']['options']);
        self::assertTrue($all['resolution']['has_more']);
    }

    public function test_review_exact_selector_does_not_hydrate_or_authorize_every_estimate(): void
    {
        for ($index = 1; $index <= 100; $index++) {
            $this->estimate('SM-UNRELATED-'.$index, 'Другая смета '.$index);
        }
        $target = $this->estimate('SM-EXACT', 'Точная смета');
        $hydrated = 0;
        $queries = 0;
        Event::listen('eloquent.retrieved: '.Estimate::class, function () use (&$hydrated): void {
            $hydrated++;
        });
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, '"estimates"')) {
                $queries++;
            }
        });

        $result = app(GetEstimatePositionsTool::class)->execute(['estimate_selector' => 'SM-EXACT'], $this->actor, $this->organization);
        self::assertIsArray($result);
        self::assertSame($target->id, $result['estimate']['id']);
        self::assertLessThanOrEqual(2, $hydrated);
        self::assertLessThanOrEqual(8, $queries);
    }

    public function test_review_resource_reference_rechecks_project_finance_after_it_is_revoked(): void
    {
        $estimate = $this->estimate('SM-PROOF', 'Смета для проверки доступа к источнику');
        $item = $this->item($estimate, '1', 'Проверяемая работа');
        EstimateItemResource::query()->create(['estimate_item_id' => $item->id, 'resource_type' => 'labor',
            'name' => 'Проверяемый ресурс', 'quantity_per_unit' => '1.0000', 'total_quantity' => '2.0000',
            'unit_price' => '5.00', 'total_amount' => '10.00']);
        $result = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $estimate->id, 'include_composition' => true], $this->actor, $this->organization);
        self::assertIsArray($result);
        $policy = app(AssistantDataAccessPolicy::class);
        foreach ($result['structured_fact_evidence']['source_refs'] as $reference) {
            self::assertTrue($policy->canReadReference($this->actor, $this->organization->id, $reference));
        }

        $this->financeDeniedProjectIds = [$this->project->id];
        self::assertTrue($policy->canCurrentPermission($this->actor, $this->organization->id, 'budget-estimates.finance.view'));
        foreach ($result['structured_fact_evidence']['source_refs'] as $reference) {
            self::assertFalse($policy->canReadReference($this->actor, $this->organization->id, $reference));
        }
        $this->financeDeniedProjectIds = [];
        $otherProject = Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]);
        $resourceReference = array_values(array_filter($result['source_refs'], static fn (array $source): bool => $source['entity_type'] === 'estimate_item_resource'))[0];
        $resourceReference['project_id'] = $otherProject->id;
        self::assertFalse($policy->canReadReference($this->actor, $this->organization->id, $resourceReference));
    }

    private function estimate(string $number, string $name, ?Organization $organization = null, ?Project $project = null): Estimate
    {
        $organization ??= $this->organization;
        $project ??= $this->project;

        return Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => $number, 'name' => $name, 'estimate_date' => '2026-09-29']);
    }

    private function item(Estimate $estimate, string $number, string $name, array $attributes = []): EstimateItem
    {
        return EstimateItem::query()->create($attributes + ['estimate_id' => $estimate->id, 'position_number' => $number,
            'name' => $name, 'item_type' => 'work', 'quantity' => '1.0000', 'quantity_total' => '1.0000',
            'unit_price' => '1.00', 'direct_costs' => '1.00', 'overhead_amount' => '0.00', 'profit_amount' => '0.00',
            'total_amount' => '1.00', 'is_manual' => true]);
    }

    private function permission(string $permission, ?array $context = null): bool
    {
        return $permission !== 'budget-estimates.finance.view' || ($this->financeAllowed
            && ! in_array($context['project_id'] ?? null, $this->financeDeniedProjectIds, true));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

final class AssistantDomainToolsTest extends TestCase
{
    private AssistantDomainReadService $reader;
    private AssistantDataAccessPolicy $policy;
    private bool $permissions = true;
    private bool $financialPermissions = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => $this->permissions && ($this->financialPermissions || ! in_array($permission, ['budget-estimates.finance.view','finance.view'], true)));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModuleSlugs')->andReturnUsing(fn (int $organizationId): array => $entitlements->getEffectiveModules($organizationId)->pluck('slug')->all());
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'budget-estimates'], (object) ['slug' => 'quality-control'], (object) ['slug' => 'basic-warehouse']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService(), $entitlements);
        $this->policy = $policy;
        $this->reader = new AssistantDomainReadService(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $policy, $authorization);
    }

    public function test_private_projects_before_visible_match_do_not_starve_search_limit(): void
    {
        [$organization, $actor] = $this->actor();
        for ($index = 0; $index < 25; $index++) {
            Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Стройка скрытая '.$index, 'is_archived' => false]);
        }
        $visible = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Стройка доступная', 'is_archived' => false]);
        $actor->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        $result = $this->reader->execute('search', ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'Стройка', 'limit' => 1, 'fields' => ['id','name']], $actor, $organization->id);
        $this->assertCount(1, $result['results']);
        $this->assertSame($visible->id, $result['results'][0]['id']);
        $this->assertSame('/projects/'.$visible->id, $result['results'][0]['navigation']['url']);
    }

    public function test_project_lookup_uses_its_primary_key_for_verified_current_scope_and_rejects_hidden_scope(): void
    {
        [$organization, $actor] = $this->actor();
        $visible = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Текущий объект', 'is_archived' => false]);
        $other = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Другой объект', 'is_archived' => false]);
        $hidden = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Скрытый объект', 'is_archived' => false]);
        foreach ([$visible, $other] as $project) { $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']); }
        $result = $this->reader->execute('search', ['domain' => 'projects', 'entity_type' => 'project', 'query' => '',
            'project_id' => $visible->id, 'fields' => ['id', 'name']], $actor, $organization->id);
        self::assertSame([$visible->id], array_column($result['results'], 'id'));
        self::assertSame(1, $result['result_window']['total']);
        $this->expectException(AccessDeniedHttpException::class);
        $this->reader->execute('search', ['domain' => 'projects', 'entity_type' => 'project', 'query' => '',
            'project_id' => $hidden->id], $actor, $organization->id);
    }

    public function test_project_search_reports_acl_scoped_result_window_and_checked_title(): void
    {
        [$organization, $actor] = $this->actor();
        $visible = [];
        for ($index = 0; $index < 3; $index++) {
            $project = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'QA_WINDOW_'.$index,
                'status' => $index === 2 ? 'draft' : 'active', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_archived' => false]);
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
            $visible[] = $project;
        }
        Project::factory()->create(['organization_id' => $organization->id, 'name' => 'QA_WINDOW_PRIVATE', 'is_archived' => false]);

        $limited = $this->reader->execute('search', ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'QA_WINDOW_',
            'limit' => 2, 'fields' => ['id', 'name', 'status', 'start_date', 'end_date']], $actor, $organization->id);
        self::assertSame(['limit' => 2, 'returned' => 2, 'has_more' => true, 'continued' => false, 'total' => 3], array_diff_key($limited['result_window'], ['next_cursor' => true]));
        self::assertIsString($limited['result_window']['next_cursor']);
        self::assertSame($visible[0]->name, $limited['source_refs'][0]['display_label']);
        self::assertContains('name', $limited['source_refs'][0]['checked_fields']);
        self::assertStringStartsWith('2026-01-01', $limited['results'][0]['fields']['start_date']);
        self::assertStringStartsWith('2026-12-31', $limited['results'][0]['fields']['end_date']);

        $complete = $this->reader->execute('search', ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'QA_WINDOW_',
            'limit' => 3, 'fields' => ['id', 'name', 'status', 'start_date', 'end_date']], $actor, $organization->id);
        self::assertSame(['limit' => 3, 'returned' => 3, 'has_more' => false, 'continued' => false, 'next_cursor' => null, 'total' => 3], $complete['result_window']);
        self::assertCount(3, $complete['results']);
        self::assertSame(array_key_exists('retrieval_coverage', $limited), array_key_exists('retrieval_coverage', $complete));

        $unsupportedSearch = $this->reader->execute('search', ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'QA_WINDOW_',
            'limit' => 2, 'fields' => ['id']], $actor, $organization->id);
        self::assertSame(['limit' => 2, 'returned' => 2, 'has_more' => true, 'continued' => false, 'total' => 3], array_diff_key($unsupportedSearch['result_window'], ['next_cursor' => true]));
        self::assertSame(['id' => $visible[0]->id], $unsupportedSearch['results'][0]['fields']);

        $unlabelled = $this->reader->execute('read', ['domain' => 'projects', 'entity_type' => 'project', 'id' => $visible[0]->id,
            'fields' => ['id']], $actor, $organization->id);
        self::assertArrayNotHasKey('display_label', $unlabelled['source_refs'][0]);
    }

    public function test_cursor_pages_checked_rows_after_deleted_first_page_and_rechecks_project_access(): void
    {
        [$organization, $actor] = $this->actor();
        $projects = [];
        for ($index = 0; $index < 4; $index++) {
            $project = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'CURSOR_'.$index, 'is_archived' => false]);
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
            $projects[] = $project;
        }
        $arguments = ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'CURSOR_', 'limit' => 2, 'fields' => ['id', 'name']];
        $first = $this->reader->execute('search', $arguments, $actor, $organization->id);
        self::assertSame([$projects[0]->id, $projects[1]->id], array_column($first['results'], 'id'));
        $projects[0]->delete();
        $actor->assignedProjects()->detach($projects[2]->id);
        $next = $this->reader->execute('search', $arguments + ['cursor' => $first['result_window']['next_cursor']], $actor, $organization->id);

        self::assertSame([$projects[3]->id], array_column($next['results'], 'id'));
        self::assertSame(['limit' => 2, 'returned' => 1, 'has_more' => false, 'continued' => true, 'next_cursor' => null, 'total' => 2], $next['result_window']);
        self::assertSame([$projects[3]->id], array_column($next['structured_fact_evidence']['rows'], 'entity_id'));
        self::assertSame([$projects[3]->id], array_column(array_filter($next['source_refs'], static fn (array $reference): bool => $reference['entity_type'] === 'project'), 'entity_id'));
        self::assertArrayNotHasKey('_assistant_result_total', $next['results'][0]['fields']);
    }

    public function test_total_counts_all_current_accessible_matches_beyond_page_and_rechecks_revoked_assignment(): void
    {
        [$organization, $actor] = $this->actor();
        $projects = [];
        for ($index = 0; $index < 24; $index++) {
            $project = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'FULL_COUNT_'.$index,
                'address' => 'COUNT_FIXED_ADDRESS', 'description' => 'COUNT_FIXED_DESCRIPTION', 'is_archived' => false]);
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
            $projects[] = $project;
        }
        Project::factory()->create(['organization_id' => $organization->id, 'name' => 'FULL_COUNT_PRIVATE', 'is_archived' => false]);
        Project::factory()->create(['name' => 'FULL_COUNT_FOREIGN', 'is_archived' => false]);
        $arguments = ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'FULL_COUNT_', 'limit' => 20, 'fields' => ['name']];
        $first = $this->reader->execute('search', $arguments, $actor, $organization->id);

        self::assertCount(20, $first['results']);
        self::assertSame(24, $first['result_window']['total']);
        self::assertTrue($first['result_window']['has_more']);
        $countReference = $first['domain_search_count_evidence']['source_ref'];
        $this->app->instance(AssistantDomainReadService::class, $this->reader);
        $countHelper = new \App\BusinessModules\Features\AIAssistant\Services\AssistantDomainSearchCountEvidence;
        self::assertTrue($countHelper->matchesReference($actor, $organization->id, $countReference));
        $jsonb = DB::selectOne('select CAST(? AS jsonb)::text AS value', [json_encode($countReference, JSON_THROW_ON_ERROR)]);
        $persistedReference = json_decode($jsonb->value, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($countHelper->matchesReference($actor, $organization->id, $persistedReference));
        $actor->assignedProjects()->detach($projects[23]->id);
        $second = $this->reader->execute('search', $arguments + ['cursor' => $first['result_window']['next_cursor']], $actor, $organization->id);

        self::assertCount(3, $second['results']);
        self::assertSame(23, $second['result_window']['total']);
        self::assertFalse($countHelper->matchesReference($actor, $organization->id, $countReference));
        self::assertFalse($second['result_window']['has_more']);
        self::assertNotContains($projects[23]->id, array_column($second['results'], 'id'));
        $filtered = $this->reader->execute('search', array_replace($arguments, ['query' => 'FULL_COUNT_22']), $actor, $organization->id);
        self::assertSame(1, $filtered['result_window']['total'], json_encode($filtered['results'], JSON_THROW_ON_ERROR));
        self::assertCount(1, $filtered['results']);
        $privateFilter = $this->reader->execute('search', array_replace($arguments, ['query' => 'FULL_COUNT_23']), $actor, $organization->id);
        self::assertSame(0, $privateFilter['result_window']['total'], json_encode($privateFilter['results'], JSON_THROW_ON_ERROR));
        self::assertSame([], $privateFilter['results']);

        foreach ([21, 22, 23] as $index) {
            Estimate::withoutEvents(fn () => Estimate::query()->create(['organization_id' => $organization->id,
                'project_id' => $projects[$index]->id, 'number' => 'SCOPED_COUNT_'.$index,
                'name' => 'SCOPED_COUNT_'.$index, 'estimate_date' => now()->toDateString(), 'total_amount' => 0]));
        }
        $estimateArguments = ['domain' => 'estimates', 'entity_type' => 'estimate', 'query' => 'SCOPED_COUNT_', 'limit' => 1, 'fields' => ['name']];
        $allEstimates = $this->reader->execute('search', $estimateArguments, $actor, $organization->id);
        self::assertSame(2, $allEstimates['result_window']['total']);
        $oneProject = $this->reader->execute('search', $estimateArguments + ['project_id' => $projects[22]->id], $actor, $organization->id);
        self::assertSame(1, $oneProject['result_window']['total']);
        $privateProject = $this->reader->execute('search', $estimateArguments + ['project_id' => $projects[23]->id], $actor, $organization->id);
        self::assertSame(0, $privateProject['result_window']['total']);
        self::assertSame([], $privateProject['results']);
    }

    public function test_cursor_cannot_change_search_scope_or_bypass_revoked_permissions(): void
    {
        [$organization, $actor] = $this->actor();
        for ($index = 0; $index < 2; $index++) {
            $project = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'BOUND_'.$index, 'is_archived' => false]);
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        }
        $arguments = ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'BOUND_', 'limit' => 1, 'fields' => ['name']];
        $first = $this->reader->execute('search', $arguments, $actor, $organization->id);
        $arguments['cursor'] = $first['result_window']['next_cursor'];
        try {
            $this->reader->execute('search', array_replace($arguments, ['query' => 'other']), $actor, $organization->id);
            self::fail('Changed search scope must reject the cursor.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('cursor', $exception->errors());
        }
        $this->permissions = false;
        $this->app->instance(AssistantDomainReadService::class, $this->reader);
        self::assertFalse((new \App\BusinessModules\Features\AIAssistant\Services\AssistantDomainSearchCountEvidence)
            ->canReadReference($actor, $organization->id, $first['domain_search_count_evidence']['source_ref']));
        $this->expectException(AccessDeniedHttpException::class);
        $this->reader->execute('search', $arguments, $actor, $organization->id);
    }

    public function test_search_count_and_page_share_token_matches_from_all_authorized_search_columns(): void
    {
        [$organization, $actor] = $this->actor();
        $byName = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'TOKEN_COUNT_22',
            'address' => 'FIXED_ADDRESS', 'description' => 'FIXED_DESCRIPTION', 'is_archived' => false]);
        $byAddress = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'TOKEN_COUNT_OTHER',
            'address' => 'Building 122', 'description' => 'FIXED_DESCRIPTION', 'is_archived' => false]);
        $nonMatch = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'TOKEN_COUNT_NO_MATCH',
            'address' => 'FIXED_ADDRESS', 'description' => 'FIXED_DESCRIPTION', 'is_archived' => false]);
        Project::factory()->create(['organization_id' => $organization->id, 'name' => 'TOKEN_COUNT_PRIVATE_22',
            'address' => 'FIXED_ADDRESS', 'description' => 'FIXED_DESCRIPTION', 'is_archived' => false]);
        foreach ([$byName, $byAddress, $nonMatch] as $project) {
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        }
        $arguments = ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'TOKEN_COUNT_22', 'limit' => 1, 'fields' => ['name']];
        $first = $this->reader->execute('search', $arguments, $actor, $organization->id);
        self::assertSame(2, $first['result_window']['total']);
        self::assertSame([$byName->id], array_column($first['results'], 'id'));
        self::assertTrue($first['result_window']['has_more']);
        $second = $this->reader->execute('search', $arguments + ['cursor' => $first['result_window']['next_cursor']], $actor, $organization->id);
        self::assertSame(2, $second['result_window']['total']);
        self::assertSame([$byAddress->id], array_column($second['results'], 'id'));
        self::assertFalse($second['result_window']['has_more']);
        self::assertArrayNotHasKey('address', $second['results'][0]['fields']);
        self::assertSame(2, $second['domain_search_count_evidence']['value']);
    }

    public function test_money_fields_are_removed_without_financial_permission(): void
    {
        [$organization, $actor] = $this->actor();
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $estimate = Estimate::withoutEvents(fn () => Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'number' => 'PRIVATE-PRICE', 'name' => 'Смета', 'estimate_date' => now()->toDateString(), 'total_amount' => 12345]));
        $result = $this->reader->execute('read', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => $estimate->id, 'fields' => ['id','name','total_amount']], $actor, $organization->id);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Смета', $result['results'][0]['fields']['name']);
        $this->assertArrayNotHasKey('total_amount', $result['results'][0]['fields']);
        $this->assertSame('structured', $result['source_refs'][0]['content_scope']);
        $this->assertSame(['id', 'name'], $result['source_refs'][0]['checked_fields']);
        $this->assertNotContains('budget-estimates.finance.view', $result['source_refs'][0]['required_permissions']);
        $this->assertSame(['estimates'], $result['source_refs'][0]['required_domains']);
        $this->assertSame($result['fetched_at'], $result['source_refs'][0]['fetched_at']);
        $this->assertNotEmpty($result['source_refs'][0]['source_version']);
    }

    public function test_numeric_evidence_references_require_permission_for_returned_money_fields(): void
    {
        [$organization, $actor] = $this->actor();
        $this->financialPermissions = true;
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $estimate = Estimate::withoutEvents(fn () => Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'number' => 'LIVE-PRICE', 'name' => 'Смета', 'estimate_date' => now()->toDateString(), 'total_amount' => 12345]));
        $result = $this->reader->execute('read', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => $estimate->id, 'fields' => ['id', 'name', 'total_amount']], $actor, $organization->id);
        $reference = $result['financial_evidence']['source_refs'][0];
        $this->assertSame($result['source_refs'][0], $reference);
        $this->assertContains('budget-estimates.finance.view', $reference['required_permissions']);
        $this->assertContains('total_amount', $reference['checked_fields']);
        $this->assertSame($result['fetched_at'], $reference['fetched_at']);
        $this->assertSame('12345.00', $result['financial_evidence']['rows'][0]['fields']['total_amount']);
        $navigation = $this->reader->execute('navigation', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => $estimate->id], $actor, $organization->id);
        $this->assertSame(['id'], $navigation['source_refs'][0]['checked_fields']);
        $this->assertNotContains('budget-estimates.finance.view', $navigation['source_refs'][0]['required_permissions']);
    }

    public function test_navigation_rechecks_revoked_permissions(): void
    {
        [$organization, $actor] = $this->actor();
        $this->permissions = false;
        $this->expectException(AccessDeniedHttpException::class);
        $this->reader->execute('navigation', ['domain' => 'projects', 'entity_type' => 'project', 'id' => 1], $actor, $organization->id);
    }

    public function test_native_project_budget_can_be_selected_with_explicit_unknown_currency_and_current_financial_proof(): void
    {
        [$organization, $actor] = $this->actor();
        $this->financialPermissions = true;
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false, 'budget_amount' => '12345.67']);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $result = $this->reader->execute('read', ['domain' => 'projects', 'entity_type' => 'project', 'id' => $project->id,
            'fields' => ['id', 'name', 'budget_amount']], $actor, $organization->id);
        $planner = new \App\BusinessModules\Features\AIAssistant\Services\AssistantPresentationPlanner;
        $plan = json_encode(['kind' => 'verified_rows', 'version' => 1, 'result_sets' => [[
            'result_set' => $planner::providerView($result)['result_set'], 'layout' => 'list', 'columns' => ['name', 'budget_amount', 'currency'],
            'order' => ['r01'], 'group_by' => null,
        ]]], JSON_THROW_ON_ERROR);
        $checked = (new \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier)->guard('Бюджет 1', [$result], $plan, 'Какой бюджет?');

        self::assertStringContainsString('12345.67', $checked['text']);
        self::assertStringContainsString('Валюта: не указано', $checked['text']);
        self::assertSame([$project->id], array_column($checked['source_refs'], 'entity_id'));
        self::assertNull($result['results'][0]['fields']['currency']);
        self::assertArrayNotHasKey('rows', \App\BusinessModules\Features\AIAssistant\Services\AssistantToolResultProjection::forProvider('assistant_domain_read', $result)['structured_fact_evidence']);
    }

    public function test_empty_named_project_search_rechecks_scope_and_permissions(): void
    {
        [$organization, $actor] = $this->actor();
        $project = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'СевГУ', 'is_archived' => false]);
        Project::factory()->create(['name' => 'СевГУ', 'is_archived' => false]);
        $permissions = Mockery::mock(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class);
        $permissions->shouldReceive('canExecuteTool')->andReturnUsing(fn (): bool => $this->permissions);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class, $permissions);
        app()->instance(AssistantDataAccessPolicy::class, $this->policy);
        $registry = new \App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
        $registry->registerTool(new \App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\SearchProjectsTool);
        $class = new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AIAssistantService::class);
        $service = $class->newInstanceWithoutConstructor();
        foreach (['dataAccess' => $this->policy, 'permissionChecker' => $permissions, 'toolRegistry' => $registry] as $name => $value) {
            $class->getProperty($name)->setValue($service, $value);
        }
        $method = $class->getMethod('verifyEmptyNamedProjectSearch');
        $empty = [['_tool_name' => 'search_projects', 'status' => 'success', 'results' => [],
            'result_window' => ['returned' => 0, 'has_more' => false]]];
        $check = fn () => $method->invoke($service, 'В проекте СевГУ сколько кондиционеров?', $empty, $actor, $organization->id);
        self::assertSame(trans_message('ai_assistant.project_search_no_matches'), $check()['text']);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        self::assertNull($check());
        $actor->assignedProjects()->detach($project->id);
        self::assertSame([], $check()['source_refs']);
        $this->permissions = false;
        self::assertNull($check());
    }

    private function actor(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        return [$organization, $actor];
    }

    public function test_confirmed_work_predicate_reads_actual_work_and_revalidates_related_type_name(): void
    {
        [$organization, $actor] = $this->actor();
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $type = \App\Models\WorkType::query()->create(['organization_id' => $organization->id, 'name' => 'Монтаж колонн', 'code' => 'ASSISTANT-CONFIRMED']);
        $base = ['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'quantity' => '1.0000', 'price' => '2.00', 'total_amount' => '2.00', 'completion_date' => '2026-10-01', 'work_type_id' => $type->id];
        $confirmed = \App\Models\CompletedWork::query()->create($base + ['status' => 'confirmed']);
        \App\Models\CompletedWork::query()->create($base + ['status' => 'pending']);
        $result = $this->reader->execute('search', ['domain' => 'works', 'entity_type' => 'completed_work', 'project_id' => $project->id, 'predicate' => 'confirmed', 'query' => '', 'limit' => 20], $actor, $organization->id);
        self::assertSame([$confirmed->id], array_column($result['results'], 'id'));
        self::assertSame('Монтаж колонн', $result['results'][0]['fields']['name']);
        self::assertSame(1, $result['result_window']['total']);
        self::assertSame('confirmed', $result['domain_search_count_evidence']['source_ref']['_search_count_query']['predicate']);
        $guard = new \App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard($this->policy);
        self::assertTrue($guard->fresh($actor, (int) $organization->id, [$result['source_refs'][0]]));
        $type->forceFill(['name' => 'Изменённый вид работ'])->save();
        self::assertFalse($guard->fresh($actor, (int) $organization->id, [$result['source_refs'][0]]));
    }

    public function test_unresolved_quality_predicate_excludes_resolved_and_cancelled_before_paging(): void
    {
        [$organization, $actor] = $this->actor();
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $open = null;
        foreach (['resolved', 'cancelled', 'open'] as $index => $status) {
            $record = \App\BusinessModules\Features\QualityControl\Models\QualityDefect::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'created_by' => $actor->id,
                'defect_number' => 'ASSISTANT-Q-'.$index, 'title' => 'Замечание '.$index, 'status' => $status,
            ]);
            if ($status === 'open') { $open = $record; }
        }
        $result = $this->reader->execute('search', ['domain' => 'quality', 'entity_type' => 'quality_defect', 'project_id' => $project->id, 'predicate' => 'unresolved', 'query' => '', 'limit' => 1], $actor, $organization->id);
        self::assertSame([$open->id], array_column($result['results'], 'id'));
        self::assertSame(1, $result['result_window']['total']);
        self::assertFalse($result['result_window']['has_more']);
        $answer = (new \App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier)->guard(
            'Какие незакрытые замечания есть по качеству на нашем объекте?', 'Устранённые замечания', [$result], ['project_id' => (int) $project->id],
        );
        self::assertFalse($answer['needs_clarification']);
        self::assertStringContainsString('Замечание 2', $answer['text']);
        self::assertStringNotContainsString('Замечание 0', $answer['text']);
        self::assertTrue(\App\BusinessModules\Features\AIAssistant\Services\AssistantDomainSearchCountEvidence::trusted($result['domain_search_count_evidence']));
    }

    public function test_supplier_waiting_predicate_reads_purchase_deliveries_with_current_material_and_unit(): void
    {
        [$organization, $actor] = $this->actor();
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $unit = \App\Models\MeasurementUnit::query()->firstOrCreate(['organization_id' => $organization->id, 'short_name' => 'кг'], ['name' => 'Килограмм']);
        $material = \App\Models\Material::query()->create(['organization_id' => $organization->id, 'name' => 'Цемент М500', 'measurement_unit_id' => $unit->id]);
        $base = ['organization_id' => $organization->id, 'project_id' => $project->id, 'material_id' => $material->id, 'requested_quantity' => '100.000'];
        $expected = null;
        foreach ([['purchase', 'accepted'], ['warehouse', 'processing'], ['purchase', 'processing']] as [$source, $status]) {
            $record = \App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery::query()->create($base + ['source_type' => $source, 'status' => $status]);
            if ($source === 'purchase' && $status === 'processing') { $expected = $record; }
        }
        $result = $this->reader->execute('search', ['domain' => 'warehouse', 'entity_type' => 'project_material_delivery', 'project_id' => $project->id, 'predicate' => 'supplier_waiting', 'query' => '', 'limit' => 1], $actor, $organization->id);
        self::assertSame([$expected->id], array_column($result['results'], 'id'));
        self::assertSame('Цемент М500', $result['results'][0]['fields']['name']);
        self::assertSame('кг', $result['results'][0]['fields']['material_unit']);
        self::assertSame('100.000', $result['results'][0]['fields']['requested_quantity']);
        $guard = new \App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard($this->policy);
        self::assertTrue($guard->fresh($actor, (int) $organization->id, [$result['source_refs'][0]]));
        $material->forceFill(['name' => 'Изменённый цемент'])->save();
        self::assertFalse($guard->fresh($actor, (int) $organization->id, [$result['source_refs'][0]]));
    }
}

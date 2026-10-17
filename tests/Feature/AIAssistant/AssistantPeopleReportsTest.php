<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Resources\Api\V1\Admin\User\ProjectTeamMemberResource;
use App\Http\Resources\Api\V1\Admin\User\UserOptionResource;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use App\Services\User\AdminUserRolePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

final class AssistantPeopleReportsTest extends TestCase
{
    private AssistantCustomReportSource $sources;

    private AssistantCustomReportReference $references;

    private AssistantCustomReportBuilder $builder;

    private AssistantDataAccessPolicy $policy;

    private array $denied = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => ! in_array($permission, $this->denied, true));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $modules = collect(array_map(static fn (string $slug): object => (object) ['slug' => $slug], ['users', 'reports', 'ai-assistant', 'project-management']));
        $entitlements->shouldReceive('getEffectiveModules')->andReturn($modules);
        $entitlements->shouldReceive('getEffectiveModuleSlugs')->andReturn($modules->pluck('slug')->all());
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $entitlements);
        $this->sources = new AssistantCustomReportSource(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $this->policy);
        $this->references = new AssistantCustomReportReference($this->sources, $this->policy);
        $this->builder = new AssistantCustomReportBuilder($this->sources, $this->references, $this->policy);
    }

    public function test_people_report_uses_all_current_organization_members_and_fresh_org_roles_in_two_real_sheets(): void
    {
        $org = Organization::factory()->create();
        $actor = $this->member($org, 'Руководитель', true, true, 'Директор');
        $context = AuthorizationContext::getOrganizationContext($org->id);
        $this->role($actor, $context, 'organization_owner');
        $worker = $this->member($org, 'Монтажник', true, true, 'Мастер');
        $this->role($worker, $context, 'worker');
        $this->role($worker, $context, 'foreman');
        $disabled = $this->member($org, 'Отключённый аккаунт', false, true);
        $this->role($disabled, $context, 'worker');
        $inactive = $this->member($org, 'Неактивное членство', true, false);
        $this->role($inactive, $context, 'supplier');
        $expired = $this->member($org, 'Истёкшая роль', true, true);
        $this->role($expired, $context, 'worker');
        $this->role($expired, $context, 'organization_admin', ['expires_at' => now()->subDay()]);
        $this->role($expired, $context, 'accountant', ['is_active' => false]);
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $this->role($worker, AuthorizationContext::getProjectContext($project->id, $org->id), 'organization_admin');
        $foreign = Organization::factory()->create();
        $foreignUser = $this->member($foreign, 'Чужой сотрудник', true, true, 'Чужая должность');
        $this->role($foreignUser, AuthorizationContext::getOrganizationContext($foreign->id), 'organization_owner');
        $this->role($worker, AuthorizationContext::getOrganizationContext($foreign->id), 'organization_admin');
        $fields = $this->sources->resolve($actor, $org->id, 'people', 'user', null)['fields'];
        foreach (['position', 'is_active', 'roles', 'primary_role', 'primary_role_label'] as $field) {
            self::assertArrayHasKey($field, $fields);
        }
        $section = $this->section(['columns' => ['id', 'name', 'position', 'is_active', 'roles', 'primary_role', 'primary_role_label']]);
        $plan = ['title' => 'Сотрудники организации', 'format' => 'xlsx', 'sections' => [$section,
            $this->section(['title' => 'По основной роли', 'columns' => ['primary_role_label'], 'group_by' => ['primary_role_label'],
                'metrics' => [['field' => 'id', 'operation' => 'count', 'label' => 'Сотрудников']]])]];
        $report = $this->builder->build($plan, $actor, $org->id);
        self::assertSame(5, $report['sections'][0]['source_row_count']);
        $rows = array_column($report['sections'][0]['rows'], null, 0);
        self::assertSame([$actor->id, $worker->id, $disabled->id, $inactive->id, $expired->id], array_keys($rows));
        self::assertSame('Директор', $rows[$actor->id][2]);
        self::assertTrue($rows[$worker->id][3]);
        self::assertFalse($rows[$disabled->id][3]);
        self::assertFalse($rows[$inactive->id][3]);
        self::assertNull($rows[$inactive->id][2]);
        self::assertSame('foreman', $rows[$worker->id][5]);
        self::assertSame('worker', $rows[$expired->id][5]);
        $labels = app(AdminUserRolePolicy::class);
        self::assertStringContainsString($labels->labelFor('worker'), $rows[$worker->id][4]);
        self::assertStringContainsString($labels->labelFor('foreman'), $rows[$worker->id][4]);
        self::assertStringNotContainsString($labels->labelFor('organization_admin'), $rows[$worker->id][4]);
        self::assertStringNotContainsString('Чуж', json_encode($report, JSON_THROW_ON_ERROR));
        $expected = [$labels->labelFor('organization_owner') => 1, $labels->labelFor('foreman') => 1,
            $labels->labelFor('worker') => 2, $labels->labelFor('supplier') => 1];
        $actual = array_column($report['sections'][1]['rows'], 1, 0);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        $worker->setRelation('roleAssignments', $worker->roleAssignments()->where('context_id', $context->id)->active()->get());
        $request = Request::create('/api/v1/admin/users');
        $request->attributes->set('current_organization_id', $org->id);
        $native = (new ProjectTeamMemberResource($worker))->toArray($request);
        self::assertSame($native['primary_role'], $rows[$worker->id][5]);
        self::assertSame($native['primary_role_label'], $rows[$worker->id][6]);
        self::assertSame(3, $this->policy->entityQuery($actor, $org->id, 'user')->count());
        $stream = (new AssistantCustomReportRenderer)->render($report);
        try {
            $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
            self::assertSame(2, $book->getSheetCount());
            self::assertSame('Должность', $book->getSheet(0)->getCell('C5')->getValue());
            self::assertSame('Директор', $book->getSheet(0)->getCell('C6')->getValue());
            self::assertSame('Сотрудников', $book->getSheet(1)->getCell('B5')->getValue());
            self::assertSame(5, array_sum(array_column($book->getSheet(1)->rangeToArray('A6:B9'), 1)));
            $book->disconnectWorksheets();
        } finally {
            fclose($stream);
        }
        $reference = $report['source_refs'][0];
        self::assertTrue($this->references->canReadReference($actor, $org->id, $reference));
        $this->denied = ['users.view'];
        self::assertFalse($this->references->canReadReference($actor, $org->id, $reference));
        $this->denied = [];
        $inactive->organizations()->detach($org->id);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $reference));
    }

    public function test_people_report_filters_effective_status_keeps_empty_roles_and_prefills_real_schema(): void
    {
        $org = Organization::factory()->create();
        $actor = $this->member($org, 'Руководитель', true, true);
        $disabled = $this->member($org, 'Без роли', false, true);
        $report = $this->builder->build(['title' => 'Неактивные сотрудники', 'format' => 'xlsx', 'sections' => [
            $this->section(['columns' => ['name', 'position', 'is_active', 'roles', 'primary_role', 'primary_role_label'],
                'filters' => [['field' => 'is_active', 'operator' => 'eq', 'value' => false]],
                'order_by' => [['field' => 'primary_role_label', 'direction' => 'asc']]]),
        ]], $actor, $org->id);
        self::assertSame([[$disabled->name, null, false, null, null, trans_message('user.role_missing')]], $report['sections'][0]['rows']);
        $question = 'Сделай Excel по всем сотрудникам нашей организации: имя, должность, статус и роль в системе. На втором листе — количество сотрудников по ролям.';
        $understanding = (new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver)->resolve($question, []);
        $hints = (new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSourceHints(
            new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $this->sources, $this->policy))->forRequest($actor, $org->id, $question, $understanding);
        self::assertSame('people', $hints[0]['domain']);
        self::assertSame('user', $hints[0]['entity_type']);
        self::assertSame('primary_role_label', $hints[0]['preferred_fields']['role']);
        self::assertSame('string', $hints[0]['fields']['roles']);
        self::assertSame('boolean', $hints[0]['fields']['is_active']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $actor->organizations()->updateExistingPivot($org->id, ['is_active' => false]);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_custom_role_names_identity_and_catalog_priorities_match_both_native_resources(): void
    {
        $org = Organization::factory()->create();
        $actor = $this->member($org, 'Руководитель', true, true);
        $context = AuthorizationContext::getOrganizationContext($org->id);
        $makeRole = static fn (Organization $owner, string $slug, string $name): OrganizationCustomRole => OrganizationCustomRole::query()->create([
            'organization_id' => $owner->id, 'name' => $name, 'slug' => $slug, 'system_permissions' => [],
            'module_permissions' => [], 'interface_access' => ['admin'], 'is_active' => true, 'created_by' => $actor->id,
        ]);
        $legal = $makeRole($org, 'qa_legal', 'Юридический отдел');
        $coordinator = $makeRole($org, 'qa_coordinator', 'Координатор закупок');
        $collision = $makeRole($org, 'foreman', 'Особый куратор');
        $inactiveRole = $makeRole($org, 'qa_inactive', 'Выключенная роль');
        $legalUser = $this->member($org, 'Юрист', true, true);
        $this->role($legalUser, $context, $legal->slug, ['role_type' => UserRoleAssignment::TYPE_CUSTOM, 'custom_role_id' => $legal->id]);
        $this->role($legalUser, $context, $coordinator->slug, ['role_type' => UserRoleAssignment::TYPE_CUSTOM, 'custom_role_id' => $coordinator->id]);
        $coordinatorUser = $this->member($org, 'Координатор', true, true);
        $this->role($coordinatorUser, $context, $coordinator->slug, ['role_type' => UserRoleAssignment::TYPE_CUSTOM, 'custom_role_id' => $coordinator->id]);
        $collisionUser = $this->member($org, 'Куратор', true, true);
        $this->role($collisionUser, $context, $collision->slug, ['role_type' => UserRoleAssignment::TYPE_CUSTOM, 'custom_role_id' => $collision->id]);
        $invalid = $this->member($org, 'Неверная связь', true, true);
        $this->role($invalid, $context, $legal->slug, ['role_type' => UserRoleAssignment::TYPE_CUSTOM, 'custom_role_id' => $legal->id]);
        $foreignRole = $makeRole(Organization::factory()->create(), $legal->slug, 'Чужой юридический отдел');
        $invalid->roleAssignments()->first()->updateQuietly(['custom_role_id' => $foreignRole->id]);
        $this->role($invalid, $context, $coordinator->slug, ['role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'custom_role_id' => $coordinator->id, 'expires_at' => now()->subDay()]);
        $inactive = $this->member($org, 'Выключенная роль', true, true);
        $this->role($inactive, $context, $inactiveRole->slug, ['role_type' => UserRoleAssignment::TYPE_CUSTOM, 'custom_role_id' => $inactiveRole->id]);
        $inactiveRole->updateQuietly(['is_active' => false]);
        $functional = $this->member($org, 'Работник склада', true, true);
        $this->role($functional, $context, 'viewer');
        $this->role($functional, $context, 'storekeeper');
        $report = $this->builder->build(['title' => 'Роли сотрудников', 'format' => 'xlsx', 'sections' => [
            $this->section(['columns' => ['id', 'roles', 'primary_role', 'primary_role_label']]),
            $this->section(['columns' => ['primary_role_label'], 'group_by' => ['primary_role_label'],
                'metrics' => [['field' => 'id', 'operation' => 'count', 'label' => 'Сотрудников']]]),
        ]], $actor, $org->id);
        $rows = array_column($report['sections'][0]['rows'], null, 0);
        self::assertStringContainsString($legal->name, $rows[$legalUser->id][1]);
        self::assertStringContainsString($coordinator->name, $rows[$legalUser->id][1]);
        self::assertSame($legal->name, $rows[$legalUser->id][3]);
        self::assertSame($coordinator->name, $rows[$coordinatorUser->id][3]);
        self::assertSame($collision->name, $rows[$collisionUser->id][3]);
        self::assertNull($rows[$invalid->id][1]);
        self::assertNull($rows[$inactive->id][1]);
        self::assertSame('storekeeper', $rows[$functional->id][2]);
        self::assertStringNotContainsString('Чужой юридический', json_encode($report, JSON_THROW_ON_ERROR));
        $counts = array_column($report['sections'][1]['rows'], 1, 0);
        self::assertSame(1, $counts[$legal->name]);
        self::assertSame(1, $counts[$coordinator->name]);
        self::assertSame(3, $counts[trans_message('user.role_missing')]);
        $request = Request::create('/api/v1/admin/users');
        $request->attributes->set('current_organization_id', $org->id);
        foreach ([$legalUser, $collisionUser, $invalid, $functional] as $user) {
            $user->setRelation('roleAssignments', $user->roleAssignments()->with('customRole')->get());
            foreach ([new ProjectTeamMemberResource($user), new UserOptionResource($user)] as $resource) {
                $native = $resource->toArray($request);
                self::assertSame($rows[$user->id][2], $native['primary_role']);
                self::assertSame($rows[$user->id][3], $native['primary_role_label']);
            }
        }
        $repository = app(UserRepository::class);
        self::assertSame([$legalUser->id], $repository->paginateInOrganization($org->id, 100, ['role' => $legal->slug])->getCollection()->pluck('id')->all());
        self::assertEqualsCanonicalizing([$legalUser->id, $coordinatorUser->id], $repository
            ->paginateInOrganization($org->id, 100, ['role' => $coordinator->slug])->getCollection()->pluck('id')->all());
        self::assertSame(0, $repository->paginateInOrganization($org->id, 100, ['role' => $inactiveRole->slug])->total());
        foreach ($repository->paginateInOrganization($org->id, 100)->getCollection() as $user) {
            $native = (new ProjectTeamMemberResource($user))->toArray($request);
            self::assertSame($rows[$user->id][2], $native['primary_role']);
            self::assertSame($rows[$user->id][3], $native['primary_role_label']);
        }
    }

    private function section(array $overrides = []): array
    {
        return array_replace(['title' => 'Сотрудники', 'domain' => 'people', 'entity_type' => 'user', 'project_id' => null,
            'columns' => ['id', 'name'], 'filters' => [], 'group_by' => [], 'metrics' => [], 'order_by' => [], 'row_limit' => 100], $overrides);
    }

    private function member(Organization $org, string $name, bool $active, bool $membership, ?string $position = null): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id, 'name' => $name, 'position' => $position, 'is_active' => $active]);
        $user->organizations()->attach($org->id, ['is_active' => $membership, 'project_access_mode' => 'all_projects']);

        return $user;
    }

    private function role(User $user, AuthorizationContext $context, string $slug, array $overrides = []): void
    {
        UserRoleAssignment::query()->create(array_replace(['user_id' => $user->id, 'role_slug' => $slug,
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => $context->id, 'is_active' => true], $overrides));
    }
}

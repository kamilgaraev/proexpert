<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\BudgetEstimates\Policies\EstimatePolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateTemplateService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\WorkType;
use App\Modules\Core\AccessController;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class EstimateTemplateSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $access = Mockery::mock(AccessController::class);
        $access->shouldReceive('hasModuleAccess')->andReturnUsing(
            static fn (int $organizationId, string $slug): bool => $slug === 'budget-estimates',
        );
        $this->app->instance(AccessController::class, $access);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
    }

    public function test_public_template_can_be_applied_to_an_accessible_project(): void
    {
        $actor = $this->context();
        $foreign = $this->context();
        $template = $this->template($foreign, public: true);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);

        $this->getJson($this->url($template), $actor->authHeaders())->assertOk();
        $this->postJson($this->url($template).'/apply', $this->payload($project), $actor->authHeaders())
            ->assertCreated();

        $estimate = Estimate::query()->sole();
        self::assertSame($actor->organization->id, $estimate->organization_id);
        self::assertSame($project->id, $estimate->project_id);
        self::assertSame('20.00', $estimate->vat_rate);
        self::assertSame(1, $template->fresh()->usage_count);
    }

    public function test_viewer_cannot_delete_a_foreign_public_template_or_apply_without_create_permission(): void
    {
        $actor = $this->context('admin_viewer');
        $foreign = $this->context();
        $deletedTemplate = $this->template($foreign, public: true);
        $appliedTemplate = $this->template($foreign, public: true);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        self::assertFalse(app(EstimatePolicy::class)->create($actor->user));

        $delete = $this->deleteJson($this->url($deletedTemplate), [], $actor->authHeaders());
        $apply = $this->postJson($this->url($appliedTemplate).'/apply', $this->payload($project), $actor->authHeaders());

        self::assertSame([403, 403], [$delete->status(), $apply->status()]);
        self::assertNotNull($deletedTemplate->fresh());
        self::assertSame(0, Estimate::query()->count());
        self::assertSame(0, $appliedTemplate->fresh()->usage_count);
    }

    public function test_public_template_does_not_expose_foreign_private_catalog_records(): void
    {
        $actor = $this->context();
        $foreign = $this->context();
        $template = $this->template($foreign, public: true);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        [$workType, $unit] = $this->catalog($foreign->organization);
        $this->setTemplateItems($template, $workType, $unit);

        $this->postJson($this->url($template).'/apply', $this->payload($project), $actor->authHeaders())
            ->assertCreated();
        $item = Estimate::query()->sole()->items()->sole();
        $response = $this->getJson('/api/v1/admin/estimate-items/'.$item->id, $actor->authHeaders())->assertOk();

        self::assertFalse(str_contains($response->getContent(), 'FOREIGN-PRIVATE-CATALOG'));
        self::assertNull($item->work_type_id);
        self::assertNotSame($unit->id, $item->measurement_unit_id);
        $localUnit = MeasurementUnit::query()->findOrFail($item->measurement_unit_id);
        self::assertSame($actor->organization->id, $localUnit->organization_id);
        self::assertSame($unit->name, $localUnit->name);
        self::assertSame($unit->short_name, $localUnit->short_name);
        self::assertNull($localUnit->description);
        self::assertSame('Published snapshot', $item->name);
        self::assertSame('100.0000', $item->unit_price);
    }

    #[DataProvider('mutations')]
    public function test_mutations_require_ownership_and_management_permission(string $operation, bool $foreign): void
    {
        $actor = $this->context($foreign ? 'organization_owner' : 'admin_viewer');
        $owner = $foreign ? $this->context() : $actor;
        $template = $this->template($owner, public: $foreign);

        $response = $operation === 'delete'
            ? $this->deleteJson($this->url($template), [], $actor->authHeaders())
            : $this->postJson($this->url($template).'/share', [], $actor->authHeaders());

        $response->assertForbidden();
        self::assertNotNull($template->fresh());
        self::assertSame($foreign, $template->fresh()->is_public);

        $this->expectException(AuthorizationException::class);
        try {
            $service = app(EstimateTemplateService::class);
            $operation === 'delete'
                ? $service->delete($template, $actor->user)
                : $service->shareWithHolding($template, $actor->user);
        } finally {
            self::assertNotNull($template->fresh());
            self::assertSame($foreign, $template->fresh()->is_public);
        }
    }

    public static function mutations(): array
    {
        return [
            'foreign public delete' => ['delete', true],
            'foreign public share' => ['share', true],
            'own viewer delete' => ['delete', false],
            'own viewer share' => ['share', false],
        ];
    }

    #[DataProvider('inaccessibleProjects')]
    public function test_apply_rejects_an_inaccessible_project_without_writes(string $case): void
    {
        $actor = $this->contextWithPermissions(['budget-estimates.create']);
        $project = Project::factory()->create([
            'organization_id' => $case === 'foreign' ? Organization::factory()->create()->id : $actor->organization->id,
            'is_archived' => $case === 'archived',
        ]);
        if ($case === 'unassigned') {
            $actor->user->organizations()->updateExistingPivot($actor->organization->id, ['project_access_mode' => 'assigned_projects']);
        }
        $template = $this->template($actor);
        self::assertTrue(app(EstimatePolicy::class)->create($actor->user));

        $response = $this->postJson($this->url($template).'/apply', $this->payload($project), $actor->authHeaders());
        $case === 'foreign' ? $response->assertUnprocessable() : $response->assertForbidden();
        self::assertSame(0, Estimate::query()->count());
        self::assertSame(0, $template->fresh()->usage_count);

        $this->expectException(AuthorizationException::class);
        try {
            app(EstimateTemplateService::class)->applyTemplate($template, $this->payload($project), $actor->user);
        } finally {
            self::assertSame(0, Estimate::query()->count());
            self::assertSame(0, $template->fresh()->usage_count);
        }
    }

    public static function inaccessibleProjects(): array
    {
        return ['foreign' => ['foreign'], 'unassigned' => ['unassigned'], 'archived' => ['archived']];
    }

    public function test_apply_allows_an_assigned_collaborative_project_with_exact_create_permission(): void
    {
        $actor = $this->contextWithPermissions(['budget-estimates.create']);
        $project = Project::factory()->create();
        $project->organizations()->attach($actor->organization->id, ['role' => 'contractor', 'is_active' => true]);
        $project->users()->attach($actor->user->id, ['role' => 'member', 'is_active' => true]);
        $actor->user->organizations()->updateExistingPivot($actor->organization->id, ['project_access_mode' => 'assigned_projects']);
        $foreign = $this->context();
        $template = $this->template($foreign, public: true);

        $this->postJson($this->url($template).'/apply', $this->payload($project), $actor->authHeaders())
            ->assertCreated();

        $estimate = Estimate::query()->sole();
        self::assertSame($actor->organization->id, $estimate->organization_id);
        self::assertSame($project->id, $estimate->project_id);
        self::assertFalse(app(EstimatePolicy::class)->manageTemplates($actor->user));
    }

    public function test_service_apply_uses_session_organization_and_ignores_forged_scope_fields(): void
    {
        $actor = $this->context();
        $foreign = $this->context();
        $actor->user->organizations()->attach($foreign->organization->id, ['is_active' => true]);
        $actor->user->update(['current_organization_id' => $foreign->organization->id]);
        $actor->user->current_organization_id = $actor->organization->id;
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $template = $this->template($foreign, public: true);
        $structure = $template->template_structure;
        $structure['default_rates']['organization_id'] = $foreign->organization->id;
        $structure['default_rates']['project_id'] = $foreignProject->id;
        $template->update(['template_structure' => $structure]);

        $estimate = app(EstimateTemplateService::class)->applyTemplate($template, [
            ...$this->payload($project), 'organization_id' => $foreign->organization->id,
        ], $actor->user);

        self::assertSame($actor->organization->id, $estimate->organization_id);
        self::assertSame($project->id, $estimate->project_id);
        self::assertSame($foreign->organization->id, $actor->user->fresh()->current_organization_id);
    }

    public function test_service_apply_reloads_visibility_and_rejects_a_foreign_private_template(): void
    {
        $actor = $this->context();
        $foreign = $this->context();
        $template = $this->template($foreign, public: true);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        EstimateTemplate::query()->whereKey($template->id)->update(['is_public' => false]);

        $this->expectException(AuthorizationException::class);
        try {
            app(EstimateTemplateService::class)->applyTemplate($template, $this->payload($project), $actor->user);
        } finally {
            self::assertSame(0, Estimate::query()->count());
            self::assertSame(0, $template->fresh()->usage_count);
        }
    }

    #[DataProvider('membershipMutations')]
    public function test_service_mutations_reject_inactive_organization_membership(string $operation): void
    {
        $actor = $this->context();
        $template = $this->template($actor);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $actor->user->organizations()->updateExistingPivot($actor->organization->id, ['is_active' => false]);

        $this->expectException(AuthorizationException::class);
        try {
            $service = app(EstimateTemplateService::class);
            match ($operation) {
                'delete' => $service->delete($template, $actor->user),
                'share' => $service->shareWithHolding($template, $actor->user),
                'apply' => $service->applyTemplate($template, $this->payload($project), $actor->user),
            };
        } finally {
            self::assertNotNull($template->fresh());
            self::assertFalse($template->fresh()->is_public);
            self::assertSame(0, Estimate::query()->count());
        }
    }

    public static function membershipMutations(): array
    {
        return [['delete'], ['share'], ['apply']];
    }

    public function test_template_creation_requires_management_permission_and_access_to_source_project(): void
    {
        $actor = $this->context('admin_viewer');
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $estimate = $this->estimate($actor, $project);
        self::assertTrue(app(EstimatePolicy::class)->view($actor->user, $estimate));

        $this->postJson('/api/v1/admin/estimate-templates', [
            'estimate_id' => $estimate->id, 'name' => 'Unauthorized snapshot',
        ], $actor->authHeaders())->assertForbidden();
        self::assertSame(0, EstimateTemplate::query()->count());

        $manager = $this->contextWithPermissions(['budget-estimates.templates.manage', 'budget-estimates.view']);
        $managerProject = Project::factory()->create(['organization_id' => $manager->organization->id]);
        $managerEstimate = $this->estimate($manager, $managerProject);
        $manager->user->organizations()->updateExistingPivot($manager->organization->id, ['project_access_mode' => 'assigned_projects']);
        $this->expectException(AuthorizationException::class);
        try {
            app(EstimateTemplateService::class)->createFromEstimate($managerEstimate, 'Unauthorized project', $manager->user);
        } finally {
            self::assertSame(0, EstimateTemplate::query()->count());
        }
    }

    public function test_template_manager_can_create_share_and_delete_own_template(): void
    {
        $actor = $this->contextWithPermissions(['budget-estimates.templates.manage', 'budget-estimates.view']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $estimate = $this->estimate($actor, $project);

        $this->postJson('/api/v1/admin/estimate-templates', [
            'estimate_id' => $estimate->id, 'name' => 'Authorized snapshot',
        ], $actor->authHeaders())->assertCreated();
        $template = EstimateTemplate::query()->sole();
        self::assertSame($actor->organization->id, $template->organization_id);
        self::assertSame($actor->user->id, $template->created_by_user_id);

        $this->postJson($this->url($template).'/share', [], $actor->authHeaders())->assertOk();
        self::assertTrue($template->fresh()->is_public);
        $this->deleteJson($this->url($template), [], $actor->authHeaders())->assertOk();
        self::assertNull($template->fresh());
    }

    #[DataProvider('catalogReferences')]
    public function test_apply_preserves_own_catalog_and_public_system_unit_references(bool $systemUnit): void
    {
        $actor = $this->context();
        $owner = $systemUnit ? $this->context() : $actor;
        [$workType, $unit] = $this->catalog($owner->organization);
        if ($systemUnit) {
            $unit->update(['is_system' => true]);
        }
        $template = $this->template($owner, public: $systemUnit);
        $this->setTemplateItems($template, $workType, $unit);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);

        $this->postJson($this->url($template).'/apply', $this->payload($project), $actor->authHeaders())->assertCreated();
        $item = Estimate::query()->sole()->items()->sole();
        self::assertSame($systemUnit ? null : $workType->id, $item->work_type_id);
        self::assertSame($unit->id, $item->measurement_unit_id);
    }

    public static function catalogReferences(): array
    {
        return ['own catalog' => [false], 'public system unit' => [true]];
    }

    public function test_apply_requires_an_explicit_project(): void
    {
        $actor = $this->context();
        $template = $this->template($actor);
        $this->postJson($this->url($template).'/apply', [
            'name' => 'No project', 'estimate_date' => now()->toDateString(),
        ], $actor->authHeaders())->assertUnprocessable()->assertJsonValidationErrors('project_id');
        self::assertSame(0, Estimate::query()->count());
    }

    #[DataProvider('unitAbbreviations')]
    public function test_public_template_reuses_a_local_unit_case_insensitively(string $published, string $existing): void
    {
        $actor = $this->context();
        $foreign = $this->context();
        [$workType, $unit] = $this->catalog($foreign->organization);
        $unit->update(['short_name' => $published]);
        $localUnit = MeasurementUnit::query()->create([
            'organization_id' => $actor->organization->id, 'name' => 'Square metre',
            'short_name' => $existing, 'type' => 'work',
        ]);
        $unitsBefore = MeasurementUnit::query()->where('organization_id', $actor->organization->id)->count();
        $template = $this->template($foreign, public: true);
        $this->setTemplateItems($template, $workType, $unit);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);

        $this->postJson($this->url($template).'/apply', $this->payload($project), $actor->authHeaders())->assertCreated();

        self::assertSame($localUnit->id, Estimate::query()->sole()->items()->sole()->measurement_unit_id);
        self::assertSame($unitsBefore, MeasurementUnit::query()->where('organization_id', $actor->organization->id)->count());
        self::assertNull($localUnit->fresh()->description);
    }

    public static function unitAbbreviations(): array
    {
        return ['cyrillic' => ['секм²', 'СЕКМ²'], 'dotted I' => ['СекİN', 'секin']];
    }

    public function test_public_template_exposes_only_public_author_and_organization_fields(): void
    {
        $actor = $this->context();
        $foreign = $this->context();
        $foreign->user->update(['last_login_ip' => '198.51.100.37']);
        $template = $this->template($foreign, public: true);

        $response = $this->getJson($this->url($template), $actor->authHeaders())->assertOk();

        self::assertFalse(str_contains($response->getContent(), '198.51.100.37'));
        self::assertSame(['id', 'name'], array_keys($response->json('data.created_by')));
        self::assertSame(['id', 'name'], array_keys($response->json('data.organization')));
        $response->assertJsonPath('data.created_by.id', $foreign->user->id)
            ->assertJsonPath('data.organization.id', $foreign->organization->id);
        $list = $this->getJson('/api/v1/admin/estimate-templates', $actor->authHeaders())->assertOk();
        self::assertFalse(str_contains($list->getContent(), '198.51.100.37'));
        self::assertSame(['id', 'name'], array_keys($list->json('data.0.created_by')));
    }

    private function contextWithPermissions(array $permissions): AdminApiTestContext
    {
        $context = $this->context('admin_viewer');
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Template operator', 'created_by' => $context->user->id,
            'system_permissions' => ['admin.access'],
            'module_permissions' => ['budget-estimates' => $permissions],
            'interface_access' => ['admin'], 'is_active' => true,
        ]);
        UserRoleAssignment::assignRole($context->user, $role->slug,
            AuthorizationContext::getOrganizationContext($context->organization->id), UserRoleAssignment::TYPE_CUSTOM);

        return $context;
    }

    private function estimate(AdminApiTestContext $owner, Project $project): Estimate
    {
        return Estimate::query()->create([
            'organization_id' => $owner->organization->id, 'project_id' => $project->id,
            'number' => 'SEC-TEMPLATE-'.$project->id, 'name' => 'Source estimate',
            'type' => 'local', 'status' => 'draft', 'estimate_date' => now()->toDateString(),
            'calculation_method' => 'resource',
        ]);
    }

    private function catalog(Organization $organization): array
    {
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organization->id, 'name' => 'Published unit',
            'short_name' => 'sec-'.$organization->id, 'type' => 'work',
            'description' => 'FOREIGN-PRIVATE-CATALOG', 'is_system' => false,
        ]);
        $workType = WorkType::query()->create([
            'organization_id' => $organization->id, 'name' => 'Source work',
            'measurement_unit_id' => $unit->id, 'default_price' => 999,
            'additional_properties' => ['internal_note' => 'FOREIGN-PRIVATE-CATALOG'],
        ]);

        return [$workType, $unit];
    }

    private function setTemplateItems(EstimateTemplate $template, WorkType $workType, MeasurementUnit $unit): void
    {
        $structure = $template->template_structure;
        $structure['items'] = [[
            'position_number' => '1', 'name' => 'Published snapshot', 'default_quantity' => 2, 'default_unit_price' => 100,
            'work_type_id' => $workType->id, 'measurement_unit_id' => $unit->id,
        ]];
        $template->update(['template_structure' => $structure]);
    }

    private function context(string $role = 'organization_owner'): AdminApiTestContext
    {
        $context = AdminApiTestContext::create(roleSlug: $role);
        $context->user->organizations()->updateExistingPivot($context->organization->id, [
            'is_owner' => $role === 'organization_owner',
            'project_access_mode' => 'all_projects',
        ]);

        return $context;
    }

    private function template(AdminApiTestContext $owner, bool $public = false): EstimateTemplate
    {
        return EstimateTemplate::query()->create([
            'organization_id' => $owner->organization->id,
            'name' => 'Template security fixture',
            'created_by_user_id' => $owner->user->id,
            'is_public' => $public,
            'template_structure' => [
                'default_rates' => ['vat_rate' => 20, 'overhead_rate' => 5, 'profit_rate' => 10],
                'sections' => [],
                'items' => [],
            ],
        ]);
    }

    private function payload(Project $project): array
    {
        return [
            'project_id' => $project->id,
            'name' => 'Estimate from a template',
            'estimate_date' => now()->toDateString(),
        ];
    }

    private function url(EstimateTemplate $template): string
    {
        return '/api/v1/admin/estimate-templates/'.$template->id;
    }
}

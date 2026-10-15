<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\BudgetEstimates\Services\Library\EstimateLibraryService;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use App\Models\EstimateLibraryItemPosition;
use App\Models\EstimateSection;
use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class EstimateLibraryAuthorizationSecurityTest extends TestCase
{
    public function test_authenticated_viewer_can_read_but_cannot_publish_an_organization_library(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $viewer = $fixture->addMember(['budget-estimates' => ['view']], ['admin.access']);
        $context = $this->httpContext($fixture, $viewer);
        $library = $this->library($fixture);
        self::assertFalse((bool) $viewer->organizations()->firstOrFail()->pivot->is_owner);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($viewer, 'budget-estimates.view', [
            'context_type' => 'organization', 'organization_id' => $fixture->organization->id,
        ]));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($viewer, 'budget-estimates.templates.manage', [
            'context_type' => 'organization', 'organization_id' => $fixture->organization->id,
        ]));

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/estimates/libraries/'.$library->id)->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/estimates/libraries/'.$library->id.'/share', ['access_level' => 'public'])
            ->assertForbidden();
        self::assertSame('private', $library->fresh()->access_level);
    }

    public function test_http_estimate_editor_cannot_manage_libraries_through_alternative_writes(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['budget-estimates' => ['view', 'create', 'edit', 'delete']], ['admin.access']);
        $context = $this->httpContext($fixture, $actor);
        $library = $this->library($fixture);
        $before = $this->snapshot();
        $base = '/api/v1/admin/estimates/libraries';

        foreach (['private', 'organization', 'public'] as $level) {
            $this->withHeaders($context->authHeaders())->postJson($base.'/'.$library->id.'/share', ['access_level' => $level])->assertForbidden();
        }
        $this->withHeaders($context->authHeaders())->postJson($base, ['name' => 'Unauthorized public library', 'access_level' => 'public'])->assertForbidden();
        $this->withHeaders($context->authHeaders())->putJson($base.'/'.$library->id, ['name' => 'Unauthorized edit', 'access_level' => 'public'])->assertForbidden();
        $this->withHeaders($context->authHeaders())->deleteJson($base.'/'.$library->id)->assertForbidden();
        $this->withHeaders($context->authHeaders())->postJson($base.'/'.$library->id.'/items', ['name' => 'Unauthorized item', 'positions' => [['name' => 'Unauthorized position']]])->assertForbidden();
        $this->withHeaders($context->authHeaders())->postJson($base.'/'.$library->id.'/duplicate', ['name' => 'Unauthorized copy'])->assertForbidden();
        self::assertSame($before, $this->snapshot());
    }

    public function test_service_rejects_readers_and_estimate_editors_before_any_library_write(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $library = $this->library($fixture);
        $service = app(EstimateLibraryService::class);
        $before = $this->snapshot();
        foreach ([['view'], ['view', 'create', 'edit', 'delete']] as $permissions) {
            $actor = $fixture->addMember(['budget-estimates' => $permissions], ['admin.access']);
            foreach ([
                fn () => $service->createLibrary($actor, ['name' => 'Denied', 'access_level' => 'public']),
                fn () => $service->updateLibrary($library->id, $actor, ['access_level' => 'public']),
                fn () => $service->deleteLibrary($library->id, $actor),
                fn () => $service->createLibraryItem($library->id, $actor, ['name' => 'Denied', 'positions' => [['name' => 'Denied']]]),
                fn () => $service->shareLibrary($library->id, $actor, 'public'),
                fn () => $service->duplicateLibrary($library->id, $actor),
            ] as $operation) {
                $this->assertDenied($operation);
                self::assertSame($before, $this->snapshot());
            }
        }
        self::assertTrue((new \ReflectionMethod($service, 'addPositionToItem'))->isPrivate());
    }

    public function test_manager_can_manage_library_over_http_with_actor_bound_organization_and_creator(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $manager = $fixture->addMember(['budget-estimates' => ['view', 'templates.manage']], ['admin.access']);
        $context = $this->httpContext($fixture, $manager);
        $base = '/api/v1/admin/estimates/libraries';
        $this->withHeaders($context->authHeaders())->postJson($base, [
            'name' => 'Manager library', 'access_level' => 'public',
            'organization_id' => $fixture->foreignOrganization->id, 'created_by_user_id' => $fixture->foreignOwner->id,
        ])->assertCreated();
        $library = EstimateLibrary::query()->where('name', 'Manager library')->sole();
        self::assertSame($fixture->organization->id, $library->organization_id);
        self::assertSame($manager->id, $library->created_by_user_id);
        $this->withHeaders($context->authHeaders())->putJson($base.'/'.$library->id, ['name' => 'Updated manager library', 'access_level' => 'organization'])->assertOk();
        $this->withHeaders($context->authHeaders())->postJson($base.'/'.$library->id.'/share', ['access_level' => 'private'])->assertOk();
        $this->withHeaders($context->authHeaders())->postJson($base.'/'.$library->id.'/items', [
            'name' => 'Manager item', 'positions' => [['name' => 'Manager position', 'default_quantity' => 2]],
        ])->assertCreated();
        $item = EstimateLibraryItem::query()->where('library_id', $library->id)->sole();
        self::assertSame(1, $item->positions_count);
        self::assertSame(1, $item->positions()->count());
        $this->withHeaders($context->authHeaders())->getJson($base.'/items/'.$item->id.'/statistics')->assertOk();
        $this->withHeaders($context->authHeaders())->postJson($base.'/'.$library->id.'/duplicate', ['name' => 'Manager copy'])->assertCreated();
        $copy = EstimateLibrary::query()->where('name', 'Manager copy')->sole();
        self::assertSame('private', $copy->access_level);
        self::assertSame(1, $copy->items()->sole()->positions()->count());
        $this->withHeaders($context->authHeaders())->deleteJson($base.'/'.$copy->id)->assertOk();
        self::assertTrue($copy->fresh()->trashed());
    }

    public function test_public_source_can_be_read_copied_and_applied_but_private_or_stale_source_cannot(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $reader = $fixture->addMember(['budget-estimates' => ['view']], ['admin.access']);
        $manager = $fixture->addMember(['budget-estimates' => ['view', 'templates.manage']], ['admin.access']);
        $editor = $fixture->addMember(['budget-estimates' => ['view', 'edit']], ['admin.access']);
        $editor->organizations()->updateExistingPivot($fixture->organization->id, ['project_access_mode' => 'all_projects']);
        $library = $this->library($fixture);
        $library->update(['organization_id' => $fixture->foreignOrganization->id, 'created_by_user_id' => $fixture->foreignOwner->id, 'access_level' => 'public']);
        $item = $this->item($library);
        $estimate = $this->estimate($fixture);
        $service = app(EstimateLibraryService::class);
        $context = $this->httpContext($fixture, $reader);
        $base = '/api/v1/admin/estimates/libraries';
        $this->withHeaders($context->authHeaders())->getJson($base)->assertOk()->assertJsonFragment(['name' => $library->name]);
        $this->withHeaders($context->authHeaders())->getJson($base.'/'.$library->id)->assertOk();
        $this->withHeaders($context->authHeaders())->getJson($base.'/'.$library->id.'/items')->assertOk()->assertJsonFragment(['name' => $item->name]);
        $copy = $service->duplicateLibrary($library->id, $manager);
        self::assertSame($fixture->organization->id, $copy->organization_id);
        self::assertSame($manager->id, $copy->created_by_user_id);
        self::assertSame('private', $copy->access_level);
        self::assertCount(1, $service->applyLibraryItemToEstimate($item, $estimate, $editor));
        self::assertSame(1, EstimateItem::query()->where('estimate_id', $estimate->id)->count());
        $this->assertDenied(fn () => $service->getUsageStatistics($item->id, $reader));
        $this->assertDenied(fn () => $service->shareLibrary($library->id, $manager, 'private'));
        $library->update(['access_level' => 'private']);
        $item->setRelation('library', $library->replicate()->forceFill(['access_level' => 'public', 'organization_id' => $fixture->organization->id]));
        $before = $this->snapshot();
        foreach ([
            fn () => $service->getLibrary($library->id, $reader),
            fn () => $service->duplicateLibrary($library->id, $manager),
            fn () => $service->applyLibraryItemToEstimate($item, $estimate, $editor),
        ] as $operation) {
            $this->assertDenied($operation);
            self::assertSame($before, $this->snapshot());
        }
        $this->withHeaders($context->authHeaders())->getJson($base.'/'.$library->id)->assertNotFound();
        self::assertCount(0, $service->getLibraryItems($library->id, $reader));
        $this->withHeaders($context->authHeaders())->getJson($base.'/'.$library->id.'/items')->assertOk()->assertJsonMissing(['name' => $item->name]);
        $this->withHeaders($context->authHeaders())->getJson($base)->assertOk()->assertJsonMissing(['name' => $library->name]);
    }

    public function test_current_permissions_membership_and_entitlement_are_required_after_a_successful_write(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $service = app(EstimateLibraryService::class);
        $library = $this->library($fixture);
        foreach (['assignment', 'role', 'permission', 'subscription', 'module', 'membership', 'user', 'organization'] as $revocation) {
            $manager = $fixture->addMember(['budget-estimates' => ['view', 'templates.manage']], ['admin.access']);
            $assignment = UserRoleAssignment::query()->where('user_id', $manager->id)->sole();
            $role = OrganizationCustomRole::query()->where('slug', $assignment->role_slug)->sole();
            $context = ['context_type' => 'organization', 'organization_id' => $fixture->organization->id];
            self::assertTrue(app(AuthorizationService::class)->canCurrent($manager, 'budget-estimates.templates.manage', $context));
            $service->shareLibrary($library->id, $manager, 'public');
            app(AuthorizationService::class)->can($manager, 'budget-estimates.templates.manage', $context);
            $restore = match ($revocation) {
                'assignment' => $this->revoke($assignment, ['is_active' => false], ['is_active' => true]),
                'role' => $this->revoke($role, ['is_active' => false], ['is_active' => true]),
                'permission' => $this->revoke($role, ['module_permissions' => ['budget-estimates' => ['view']]], ['module_permissions' => ['budget-estimates' => ['view', 'templates.manage']]]),
                'subscription' => $this->revoke($fixture->subscription, ['current_period_end_at' => now()->subMinute()], ['current_period_end_at' => now()->addMonth()]),
                'module' => $this->revoke(Module::query()->where('slug', 'budget-estimates')->sole(), ['is_active' => false], ['is_active' => true]),
                'membership' => $this->revokeMembership($manager, $fixture),
                'user' => $this->revoke($manager->fresh(), ['is_active' => false], ['is_active' => true]),
                'organization' => $this->revoke($fixture->organization, ['is_active' => false], ['is_active' => true]),
            };
            $before = $this->snapshot();
            $this->assertDenied(fn () => $service->shareLibrary($library->id, $manager, 'private'));
            self::assertSame($before, $this->snapshot(), $revocation);
            $restore();
            $service->shareLibrary($library->id, $manager, 'private');
            self::assertSame('private', $library->fresh()->access_level);
        }
    }

    public function test_apply_enforces_current_edit_permission_target_scope_and_section_scope(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $editor = $fixture->addMember(['budget-estimates' => ['view', 'edit']], ['admin.access']);
        $editor->organizations()->updateExistingPivot($fixture->organization->id, ['project_access_mode' => 'all_projects']);
        $library = $this->library($fixture);
        $item = $this->item($library);
        $estimate = $this->estimate($fixture);
        $otherEstimate = $this->estimate($fixture);
        $section = EstimateSection::query()->create(['estimate_id' => $otherEstimate->id, 'name' => 'Other section', 'section_number' => '1', 'sort_order' => 1]);
        $service = app(EstimateLibraryService::class);
        $before = $this->snapshot();
        $this->assertDenied(fn () => $service->applyLibraryItemToEstimate($item, $estimate, $editor, [], $section->id));
        self::assertSame($before, $this->snapshot());
        self::assertCount(1, $service->applyLibraryItemToEstimate($item, $estimate, $editor));
        $assignment = UserRoleAssignment::query()->where('user_id', $editor->id)->sole();
        $role = OrganizationCustomRole::query()->where('slug', $assignment->role_slug)->sole();
        $role->update(['module_permissions' => ['budget-estimates' => ['view']]]);
        $before = $this->snapshot();
        $this->assertDenied(fn () => $service->applyLibraryItemToEstimate($item, $estimate, $editor));
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, EstimateItem::query()->where('estimate_id', $estimate->id)->count());
    }

    public function test_fresh_library_scope_and_current_read_permission_cannot_be_bypassed_by_cached_models(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $manager = $fixture->addMember(['budget-estimates' => ['view_all', 'templates.manage']], ['admin.access']);
        $library = $this->library($fixture);
        $item = $this->item($library);
        $service = app(EstimateLibraryService::class);
        self::assertSame($library->id, $service->getLibrary($library->id, $manager)->id);
        self::assertCount(1, $service->getLibraryItems($library->id, $manager));
        self::assertSame(0, $service->getUsageStatistics($item->id, $manager)['total_uses']);
        $assignment = UserRoleAssignment::query()->where('user_id', $manager->id)->sole();
        $role = OrganizationCustomRole::query()->where('slug', $assignment->role_slug)->sole();
        $role->update(['module_permissions' => ['budget-estimates' => ['templates.manage']]]);
        foreach ([
            fn () => $service->getLibraries($manager),
            fn () => $service->getLibrary($library->id, $manager),
            fn () => $service->getLibraryItems($library->id, $manager),
            fn () => $service->getUsageStatistics($item->id, $manager),
        ] as $operation) {
            $this->assertDenied($operation);
        }
        $role->update(['module_permissions' => ['budget-estimates' => ['view_all', 'templates.manage']]]);
        foreach ([
            ['organization_id' => $fixture->foreignOrganization->id],
            ['organization_id' => $fixture->organization->id, 'is_active' => false],
            ['organization_id' => $fixture->organization->id, 'is_active' => true, 'deleted_at' => now()],
        ] as $attributes) {
            $library->forceFill($attributes)->save();
            $before = $this->snapshot();
            foreach ([
                fn () => $service->getLibrary($library->id, $manager),
                fn () => $service->updateLibrary($library->id, $manager, ['name' => 'Forbidden']),
                fn () => $service->shareLibrary($library->id, $manager, 'public'),
                fn () => $service->deleteLibrary($library->id, $manager),
                fn () => $service->createLibraryItem($library->id, $manager, ['name' => 'Forbidden']),
            ] as $operation) {
                $this->assertDenied($operation);
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    private function revoke(\Illuminate\Database\Eloquent\Model $model, array $attributes, array $restore): \Closure
    {
        $model->update($attributes);

        return static fn () => $model->update($restore);
    }

    private function revokeMembership(User $actor, AssistantRealAuthorizationFixture $fixture): \Closure
    {
        $actor->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => false]);

        return static fn () => $actor->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => true]);
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unauthorized library operation was allowed');
        } catch (AuthorizationException|ModelNotFoundException $exception) {
            self::assertInstanceOf(\Exception::class, $exception);
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['estimate_libraries', 'estimate_library_items', 'estimate_library_item_positions', 'estimate_library_usage', 'estimate_items'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function item(EstimateLibrary $library): EstimateLibraryItem
    {
        $item = EstimateLibraryItem::query()->create(['library_id' => $library->id, 'name' => 'Fixture item', 'parameters' => [], 'positions_count' => 1]);
        EstimateLibraryItemPosition::query()->create(['library_item_id' => $item->id, 'name' => 'Fixture position', 'sort_order' => 0, 'default_quantity' => 1]);

        return $item;
    }

    private function estimate(AssistantRealAuthorizationFixture $fixture): Estimate
    {
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);

        return Estimate::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'number' => 'SEC-LIBRARY-'.$project->id, 'name' => 'Library target', 'type' => 'local',
            'status' => 'draft', 'estimate_date' => now()->toDateString(), 'calculation_method' => 'resource',
        ]);
    }

    private function library(AssistantRealAuthorizationFixture $fixture): EstimateLibrary
    {
        return EstimateLibrary::query()->create([
            'organization_id' => $fixture->organization->id,
            'created_by_user_id' => $fixture->owner->id,
            'name' => 'Library authorization fixture', 'access_level' => 'private',
            'tags' => [], 'is_active' => true,
        ]);
    }

    private function httpContext(AssistantRealAuthorizationFixture $fixture, User $actor): AdminApiTestContext
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $fixture->organization->id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Library security test', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue(
            $actor, 'admin', $sessionUuid, (int) $fixture->organization->id, false,
        );

        return new AdminApiTestContext($fixture->organization, $actor, $tokens->accessToken);
    }
}

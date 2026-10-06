<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AuthorizationCurrentReadScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_current_scope_reuses_role_and_module_reads_for_distinct_permissions(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organizationId = (int) $fixture->organization->id;
        $fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view'],
        ]]);
        $authorization = app(AuthorizationService::class)->forCurrentChecks(true);
        $connection = DB::connection();
        $connection->enableQueryLog();

        try {
            $this->assertTrue($authorization->canCurrent($fixture->member, 'ai_assistant.chat', ['organization_id' => $organizationId]));
            $firstQueries = $connection->getQueryLog();
            $this->assertNotEmpty($this->authorizationReads($firstQueries));
            $this->assertCount(1, $this->effectiveModuleListReads($firstQueries));

            $connection->flushQueryLog();
            $this->assertTrue($authorization->canCurrent($fixture->member, 'ai_assistant.chat', ['organization_id' => $organizationId]));
            $this->assertTrue($authorization->canCurrent($fixture->member, 'projects.view', ['organization_id' => $organizationId]));
            $this->assertSame([], $this->authorizationReads($connection->getQueryLog()));
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        Module::query()->where('slug', 'project-management')->update(['is_active' => false]);

        $this->assertTrue($authorization->canCurrent($fixture->member, 'projects.view', ['organization_id' => $organizationId]));
        $this->assertFalse(app(AuthorizationService::class)->forCurrentChecks(true)->canCurrent($fixture->member, 'projects.view', ['organization_id' => $organizationId]));
        $this->assertTrue(app(AuthorizationService::class)->canCurrent($fixture->member, 'ai_assistant.chat', ['organization_id' => $organizationId]));
    }

    public function test_current_role_slugs_reuse_permission_reads_and_refresh_after_revocation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $context = ['organization_id' => (int) $fixture->organization->id];
        $authorization = app(AuthorizationService::class)->forCurrentChecks(true);
        $this->assertTrue($authorization->canCurrent($fixture->member, 'ai_assistant.chat', $context));
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        try {
            $this->assertSame([$fixture->memberRole->slug], $authorization->getUserRoleSlugs($fixture->member, $context));
            $this->assertSame([$fixture->memberRole->slug], $authorization->getUserRoleSlugs($fixture->member, $context));
            $this->assertSame([], $connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $fixture->memberAssignment->update(['is_active' => false]);

        $this->assertSame([$fixture->memberRole->slug], $authorization->getUserRoleSlugs($fixture->member, $context));
        $this->assertSame([], app(AuthorizationService::class)->forCurrentChecks(true)->getUserRoleSlugs($fixture->member, $context));
    }

    public function test_project_role_reads_reuse_sibling_contexts_only_within_one_current_scope(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $projects = Project::withoutEvents(fn () => Project::factory()->count(3)->create([
            'organization_id' => $fixture->organization->id,
        ]));
        $contexts = $projects->map(fn (Project $project): AuthorizationContext =>
            AuthorizationContext::getProjectContext($project->id, $fixture->organization->id));
        $organizationContext = AuthorizationContext::findOrganizationContext($fixture->organization->id);
        $authorization = app(AuthorizationService::class)->forCurrentChecks(true);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ($contexts as $context) {
                $this->assertContains('organization_owner', $authorization->getUserRoles($fixture->owner, $context)->pluck('role_slug')->all());
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $siblings = array_filter($queries, static fn (array $query): bool =>
            str_starts_with($query['query'], 'select "id" from "authorization_contexts" where "parent_context_id" = ?'));
        $parents = array_filter($queries, static fn (array $query): bool =>
            str_starts_with($query['query'], 'select * from "authorization_contexts" where "authorization_contexts"."id" = ?')
            && (int) ($query['bindings'][0] ?? 0) === (int) $organizationContext->id);
        $this->assertCount(1, $siblings);
        $this->assertLessThanOrEqual($contexts->count(), count($parents));

        $fixture->ownerAssignment->update(['is_active' => false]);
        $fresh = app(AuthorizationService::class)->forCurrentChecks(true);
        foreach ($contexts as $context) {
            $this->assertContains('organization_owner', $authorization->getUserRoles($fixture->owner, $context)->pluck('role_slug')->all());
            $this->assertNotContains('organization_owner', $fresh->getUserRoles($fixture->owner, $context)->pluck('role_slug')->all());
        }
    }

    public function test_current_role_slugs_fail_closed_without_creating_an_organization_context(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->verified()->create());
        $context = ['organization_id' => (int) $organization->id];
        $contextCount = AuthorizationContext::query()->count();
        $authorization = app(AuthorizationService::class)->forCurrentChecks(true);

        $this->assertFalse(AuthorizationContext::query()->where('type', 'organization')->where('resource_id', $organization->id)->exists());
        $this->assertSame([], $authorization->getUserRoleSlugs($fixture->member, $context));
        $this->assertSame([], $authorization->getUserRoleSlugs($fixture->member, $context));
        $this->assertSame($contextCount, AuthorizationContext::query()->count());
    }

    public function test_sibling_project_roles_are_isolated_and_refresh_after_reparenting(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $sibling = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $foreign = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]));
        $context = AuthorizationContext::getProjectContext($project->id, $fixture->organization->id);
        $siblingContext = AuthorizationContext::getProjectContext($sibling->id, $fixture->organization->id);
        $foreignContext = AuthorizationContext::getProjectContext($foreign->id, $fixture->foreignOrganization->id);
        $siblingRole = $fixture->ownerAssignment->replicate();
        $siblingRole->forceFill(['context_id' => $siblingContext->id, 'role_slug' => 'organization_admin'])->save();
        $foreignRole = $fixture->ownerAssignment->replicate();
        $foreignRole->forceFill(['context_id' => $foreignContext->id, 'role_slug' => 'organization_admin'])->save();
        $authorization = app(AuthorizationService::class)->forCurrentChecks(true);
        $roles = $authorization->getUserRoles($fixture->owner, $context)->modelKeys();
        $this->assertContains($siblingRole->id, $roles);
        $this->assertNotContains($foreignRole->id, $roles);

        $siblingContext->update(['parent_context_id' => $foreignContext->parent_context_id]);
        $this->assertContains($siblingRole->id, $authorization->getUserRoles($fixture->owner, $context)->modelKeys());
        $freshRoles = app(AuthorizationService::class)->forCurrentChecks(true)->getUserRoles($fixture->owner, $context->fresh())->modelKeys();
        $this->assertNotContains($siblingRole->id, $freshRoles);
        $this->assertNotContains($foreignRole->id, $freshRoles);

        $siblingContext->update(['parent_context_id' => $context->parent_context_id]);
        $restored = app(AuthorizationService::class)->forCurrentChecks(true)->getUserRoles($fixture->owner, $context->fresh())->modelKeys();
        $this->assertContains($siblingRole->id, $restored);
        $this->assertNotContains($foreignRole->id, $restored);
    }

    public function test_current_scopes_ignore_stale_shared_decisions_and_do_not_publish_them(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $authorization = app(AuthorizationService::class);
        $context = ['organization_id' => (int) $fixture->organization->id];
        $permission = 'ai_assistant.chat';

        $this->assertTrue($authorization->can($fixture->member, $permission, $context));
        $scope = $authorization->forCurrentChecks(true);
        $this->assertTrue($scope->canCurrent($fixture->member, $permission, $context));
        $this->assertFalse($scope->canCurrent($fixture->foreignOwner, $permission, $context));
        $this->assertFalse($scope->canCurrent($fixture->member, $permission, ['organization_id' => (int) $fixture->foreignOrganization->id]));

        $fixture->memberAssignment->update(['is_active' => false]);

        $this->assertTrue($scope->canCurrent($fixture->member, $permission, $context));
        $this->assertFalse($authorization->forCurrentChecks(true)->canCurrent($fixture->member, $permission, $context));
        $this->assertFalse($authorization->canCurrent($fixture->member, $permission, $context));

        $fixture->memberAssignment->update(['is_active' => true]);
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.usage.view']]]);

        $sharedDecisionKey = 'permission_v4_r'.Cache::get('authorization_roles_revision', 0).'_'
            .$fixture->member->id.'_'.$fixture->memberRole->slug.'_'.$permission.'_'
            .md5(json_encode($context)).'_v'
            .Cache::get('user_permission_version_'.$fixture->member->id, 0).'_'
            .Cache::get('permission_global_version', 0);
        Cache::forget($sharedDecisionKey);

        $this->assertFalse($authorization->forCurrentChecks(true)->canCurrent($fixture->member, $permission, $context));
        $this->assertFalse($authorization->canCurrent($fixture->member, $permission, $context));
        $this->assertNull(Cache::get($sharedDecisionKey));

        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => [$permission]]]);
        $this->assertTrue($authorization->forCurrentChecks(true)->canCurrent($fixture->member, $permission, $context));
        $fixture->subscription->update(['current_period_end_at' => now()->subSecond()]);
        $this->assertFalse($authorization->forCurrentChecks(true)->canCurrent($fixture->member, $permission, $context));
        $this->assertFalse($authorization->canCurrent($fixture->member, $permission, $context));
    }

    public function test_assistant_policy_discards_compiled_authorization_after_each_operation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organizationId = (int) $fixture->organization->id;
        $fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view'],
        ]]);
        $project = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $fixture->member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $policy = app(AssistantDataAccessPolicy::class);

        $this->assertTrue($policy->entityQuery($fixture->member, $organizationId, 'project')?->whereKey($project->id)->exists());

        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat']]]);

        $this->assertFalse($policy->canReadDomain($fixture->member, $organizationId, 'projects'));
        $this->assertNull($policy->entityQuery($fixture->member, $organizationId, 'project'));
    }

    private function authorizationReads(array $queries): array
    {
        return array_values(array_filter($queries, static function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'user_role_assignments')
                || str_contains($sql, 'organization_custom_roles')
                || str_contains($sql, 'organization_package_subscriptions')
                || str_contains($sql, 'from "modules"');
        }));
    }

    private function effectiveModuleListReads(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool =>
            str_contains(strtolower($query['query']), 'select * from "modules"')));
    }
}

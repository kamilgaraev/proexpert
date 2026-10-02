<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
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

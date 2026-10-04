<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessFilter;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class AssistantKnowledgeContextMemoizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_context_is_memoized_per_actor_organization_surface_and_refreshed_between_frames(): void
    {
        $organizationA = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $organizationB = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $actorA = User::withoutEvents(fn (): User => User::factory()->create([
            'current_organization_id' => $organizationA->id,
            'is_active' => true,
        ]));
        $actorB = User::withoutEvents(fn (): User => User::factory()->create([
            'current_organization_id' => $organizationB->id,
            'is_active' => true,
        ]));
        foreach ([[$actorA, $organizationA], [$actorB, $organizationB]] as [$actor, $organization]) {
            $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
            AuthorizationContext::getOrganizationContext($organization->id);
        }

        $permissions = [$actorA->id => ['knowledge.read'], $actorB->id => ['knowledge.finance']];
        $roleSlugs = [$actorA->id => ['company-owner'], $actorB->id => ['company-accountant']];
        $modulesByOrganization = [$organizationA->id => ['alpha'], $organizationB->id => ['finance']];
        $permissionCalls = [];
        $roleCalls = [];
        $moduleCalls = [];
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->zeroOrMoreTimes()->andReturnSelf();
        $authorization->shouldReceive('getUserPermissions')->zeroOrMoreTimes()->andReturnUsing(
            static function (User $user) use (&$permissions, &$permissionCalls): array {
                $permissionCalls[$user->id] = ($permissionCalls[$user->id] ?? 0) + 1;
                return $permissions[$user->id] ?? [];
            }
        );
        $authorization->shouldReceive('canCurrent')->zeroOrMoreTimes()->andReturnUsing(
            static function (User $user, string $permission) use (&$permissions): bool {
                return in_array($permission, $permissions[$user->id] ?? [], true);
            }
        );
        $authorization->shouldReceive('getUserRoles')->zeroOrMoreTimes()->andReturnUsing(
            static function (User $user) use (&$roleSlugs, &$roleCalls): Collection {
                $roleCalls[$user->id] = ($roleCalls[$user->id] ?? 0) + 1;
                return collect(array_map(static fn (string $slug): object => (object) ['role_slug' => $slug], $roleSlugs[$user->id] ?? []));
            }
        );
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->zeroOrMoreTimes()->andReturnUsing(
            static function (int $organizationId) use (&$modulesByOrganization, &$moduleCalls): Collection {
                $moduleCalls[$organizationId] = ($moduleCalls[$organizationId] ?? 0) + 1;
                return collect(array_map(static fn (string $slug): object => (object) ['slug' => $slug], $modulesByOrganization[$organizationId] ?? []));
            }
        );
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $entitlements);
        $policy->setTrustedSurface(KnowledgeSurface::ADMIN);

        $this->article('actor-a-owner', ['owner'], ['knowledge.read'], ['alpha'], ['admin']);
        $this->article('actor-a-worker', ['worker'], ['knowledge.edit'], ['beta'], ['admin']);
        $this->article('actor-a-lk', ['worker'], ['knowledge.edit'], ['beta'], ['lk']);
        $this->article('actor-b-accountant', ['accountant'], ['knowledge.finance'], ['finance'], ['admin']);

        $queryCounts = [
            'actor_a_first' => ['authorization_contexts' => 0, 'user_role_assignments' => 0],
            'actor_b' => ['authorization_contexts' => 0, 'user_role_assignments' => 0],
            'actor_a_updated' => ['authorization_contexts' => 0, 'user_role_assignments' => 0],
            'actor_a_lk' => ['authorization_contexts' => 0, 'user_role_assignments' => 0],
        ];
        $frame = 'none';
        DB::listen(static function ($query) use (&$queryCounts, &$frame): void {
            $sql = strtolower((string) $query->sql);
            if (str_contains($sql, 'authorization_contexts')) {
                $queryCounts[$frame]['authorization_contexts'] = ($queryCounts[$frame]['authorization_contexts'] ?? 0) + 1;
            }
            if (str_contains($sql, 'user_role_assignments')) {
                $queryCounts[$frame]['user_role_assignments'] = ($queryCounts[$frame]['user_role_assignments'] ?? 0) + 1;
            }
        });

        $frame = 'actor_a_first';
        $visibleA = $policy->withCurrentChecks($actorA, $organizationA->id, function () use ($policy, $actorA, $organizationA): array {
            $first = $policy->entityQuery($actorA, $organizationA->id, 'knowledge_article')->pluck('slug')->all();
            $second = $policy->entityQuery($actorA, $organizationA->id, 'knowledge_article')->pluck('slug')->all();
            $this->assertContains('actor-a-owner', $first);
            $this->assertNotContains('actor-a-worker', $first);
            $this->assertNotContains('actor-b-accountant', $first);
            $this->assertSame($first, $second);
            return $first;
        }, fresh: true);

        $frame = 'actor_b';
        $visibleB = $policy->withCurrentChecks($actorB, $organizationB->id, function () use ($policy, $actorB, $organizationB): array {
            $first = $policy->entityQuery($actorB, $organizationB->id, 'knowledge_article')->pluck('slug')->all();
            $second = $policy->entityQuery($actorB, $organizationB->id, 'knowledge_article')->pluck('slug')->all();
            $this->assertContains('actor-b-accountant', $first);
            $this->assertNotContains('actor-a-owner', $first);
            $this->assertNotContains('actor-a-worker', $first);
            $this->assertSame($first, $second);
            return $first;
        }, fresh: true);

        $permissions[$actorA->id] = ['knowledge.edit'];
        $roleSlugs[$actorA->id] = ['company-worker'];
        $modulesByOrganization[$organizationA->id] = ['beta'];
        $frame = 'actor_a_updated';
        $visibleAUpdated = $policy->withCurrentChecks($actorA, $organizationA->id, function () use ($policy, $actorA, $organizationA): array {
            return $policy->entityQuery($actorA, $organizationA->id, 'knowledge_article')->pluck('slug')->all();
        }, fresh: true);

        $policy->setTrustedSurface(KnowledgeSurface::LK);
        $frame = 'actor_a_lk';
        $visibleALk = $policy->withCurrentChecks($actorA, $organizationA->id, function () use ($policy, $actorA, $organizationA): array {
            return $policy->entityQuery($actorA, $organizationA->id, 'knowledge_article')->pluck('slug')->all();
        }, fresh: true);

        $this->assertContains('actor-a-owner', $visibleA);
        $this->assertNotContains('actor-a-worker', $visibleA);
        $this->assertContains('actor-b-accountant', $visibleB);
        $this->assertNotContains('actor-a-owner', $visibleB);
        $this->assertContains('actor-a-worker', $visibleAUpdated);
        $this->assertNotContains('actor-a-owner', $visibleAUpdated);
        $this->assertNotContains('actor-a-lk', $visibleAUpdated);
        $this->assertContains('actor-a-lk', $visibleALk);
        $this->assertNotContains('actor-a-worker', $visibleALk);
        $this->assertSame(3, $permissionCalls[$actorA->id] ?? 0);
        $this->assertSame(1, $permissionCalls[$actorB->id] ?? 0);
        $this->assertSame(3, $roleCalls[$actorA->id] ?? 0);
        $this->assertSame(1, $roleCalls[$actorB->id] ?? 0);
        $this->assertSame(3, $moduleCalls[$organizationA->id] ?? 0);
        $this->assertSame(1, $moduleCalls[$organizationB->id] ?? 0);
        foreach (['actor_a_first', 'actor_b', 'actor_a_updated', 'actor_a_lk'] as $frameName) {
            $this->assertSame(1, $queryCounts[$frameName]['authorization_contexts'], $frameName);
            $this->assertSame(0, $queryCounts[$frameName]['user_role_assignments'], $frameName);
        }
    }

    public function test_json_access_filters_keep_empty_multiple_and_nonmatching_restrictions(): void
    {
        $expected = [];
        foreach (['surfaces' => 'admin', 'audiences' => 'owner', 'permission_keys' => 'knowledge.read', 'module_slugs' => 'alpha'] as $column => $allowed) {
            foreach ([null, [], ['other', $allowed], ['other', 42, false]] as $index => $restriction) {
                $slug = 'filter-'.$column.'-'.$index;
                $this->article($slug, ['owner'], ['knowledge.read'], ['alpha'], ['admin']);
                DB::table('knowledge_articles')->where('slug', $slug)->update([
                    $column => $restriction === null ? null : json_encode($restriction, JSON_THROW_ON_ERROR),
                ]);
                if ($index < 3) { $expected[] = $slug; }
            }
        }
        $quotedPermission = 'права."\\пример';
        $this->article('quoted-permission', ['owner'], [$quotedPermission], ['alpha'], ['admin']);
        $expected[] = 'quoted-permission';
        $context = new KnowledgeAccessContext(KnowledgeSurface::ADMIN, ['all', 'owner'], ['unused', 'knowledge.read', $quotedPermission],
            ['other-module', 'alpha'], null, null, null, null, null);
        $visible = (new KnowledgeAccessFilter)->apply(KnowledgeArticle::query()->where(function ($query): void {
            $query->where('slug', 'like', 'filter-%')->orWhere('slug', 'quoted-permission');
        }), $context)->pluck('slug')->all();
        $this->assertEqualsCanonicalizing($expected, $visible);

        $this->article('empty-restrictions', [], [], [], []);
        $context = new KnowledgeAccessContext(KnowledgeSurface::ADMIN, [], [], [], null, null, null, null, null);
        $visible = (new KnowledgeAccessFilter)->apply(KnowledgeArticle::query()->where(function ($query): void {
            $query->where('slug', 'like', 'filter-%')->orWhere('slug', 'quoted-permission')->orWhere('slug', 'empty-restrictions');
        }), $context)->pluck('slug')->all();
        $this->assertSame(['empty-restrictions'], $visible);
    }

    private function article(string $slug, array $audiences, array $permissions, array $modules, array $surfaces): void
    {
        DB::table('knowledge_articles')->insert([
            'kind' => 'guide',
            'status' => 'published',
            'title' => $slug,
            'slug' => $slug,
            'audiences' => json_encode($audiences, JSON_THROW_ON_ERROR),
            'permission_keys' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'module_slugs' => json_encode($modules, JSON_THROW_ON_ERROR),
            'surfaces' => json_encode($surfaces, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class AssistantRestrictedProjectScopeTest extends TestCase
{
    use RefreshDatabase;

    private AssistantDataAccessPolicy $policy;
    private Organization $organization;
    private User $actor;
    private Project $hidden;
    private int $restrictionQueries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnTrue();
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect(['ai-assistant', 'project-management', 'budgeting'])
            ->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->actor = $this->actor($this->organization);
        $visible = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->actor->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'select exists(')
                && str_contains($query->sql, 'from "projects"')
                && str_contains($query->sql, '"id" not in')) {
                $this->restrictionQueries++;
            }
        });
    }

    public function test_distinct_organization_aggregates_share_one_restriction_check_in_current_frame(): void
    {
        $this->assertSame([false, false], $this->access($this->actor, $this->organization));
        $this->assertSame(1, $this->restrictionQueries);
    }

    public function test_fresh_frames_recheck_assignment_grant_and_revocation(): void
    {
        $this->assertSame([false, false], $this->access($this->actor, $this->organization));
        $this->actor->assignedProjects()->attach($this->hidden->id, ['is_active' => true, 'role' => 'member']);
        $this->assertSame([true, true], $this->access($this->actor, $this->organization));
        $this->actor->assignedProjects()->updateExistingPivot($this->hidden->id, ['is_active' => false]);
        $this->assertSame([false, false], $this->access($this->actor, $this->organization));
        $this->assertSame(3, $this->restrictionQueries);
    }

    public function test_current_frames_isolate_actor_and_organization_scope(): void
    {
        $otherActor = $this->actor($this->organization, 'all_projects');
        $otherOrganization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $otherOrganizationActor = $this->actor($otherOrganization, 'all_projects');
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $otherOrganization->id, 'is_archived' => false]));

        $this->policy->withCurrentChecks($this->actor, $this->organization->id, function () use ($otherActor, $otherOrganization, $otherOrganizationActor): void {
            $this->assertSame([false, false], $this->aggregateAccess($this->actor, $this->organization));
            $this->assertSame([true, true], $this->access($otherActor, $this->organization));
            $this->assertSame([true, true], $this->access($otherOrganizationActor, $otherOrganization));
            $this->assertSame([false, false], $this->aggregateAccess($this->actor, $this->organization));
        }, fresh: true);
    }

    public function test_compiler_without_current_frame_does_not_reuse_previous_assignment_scope(): void
    {
        $this->assertNull($this->policy->entityQuery($this->actor, $this->organization->id, 'management_pnl_snapshot'));
        $this->actor->assignedProjects()->attach($this->hidden->id, ['is_active' => true, 'role' => 'member']);
        $this->assertNotNull($this->policy->entityQuery($this->actor, $this->organization->id, 'project_finance_snapshot'));
        $this->actor->assignedProjects()->updateExistingPivot($this->hidden->id, ['is_active' => false]);
        $this->assertNull($this->policy->entityQuery($this->actor, $this->organization->id, 'project_finance_snapshot'));
        $this->assertSame(3, $this->restrictionQueries);
    }

    private function actor(Organization $organization, string $mode = 'assigned_projects'): User
    {
        $actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]));
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => $mode]);

        return $actor;
    }

    private function access(User $actor, Organization $organization): array
    {
        return $this->policy->withCurrentChecks($actor, $organization->id,
            fn (): array => $this->aggregateAccess($actor, $organization), fresh: true);
    }

    private function aggregateAccess(User $actor, Organization $organization): array
    {
        return array_map(fn (string $type): bool => $this->policy->entityQuery($actor, $organization->id, $type) !== null,
            ['management_pnl_snapshot', 'project_finance_snapshot']);
    }
}

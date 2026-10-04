<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class AssistantReferenceBatchTest extends TestCase
{
    use RefreshDatabase;

    private AssistantDataAccessPolicy $policy;
    private AssistantSourceReferenceGuard $guard;
    private Organization $organization;
    private User $actor;
    private ?\Closure $duringPermission = null;

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(function (User $actor, string $permission): bool {
            $this->duringPermission?->__invoke($permission);
            return true;
        });
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('getUserRoles')->andReturn(collect());
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect(['ai-assistant', 'project-management', 'contract-management', 'payments', 'budget-estimates'])
            ->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->guard = new AssistantSourceReferenceGuard($this->policy);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]));
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
    }

    public function test_native_access_queries_remain_bounded_as_reference_count_grows(): void
    {
        $projects = Project::withoutEvents(fn () => Project::factory()->count(48)->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->actor->assignedProjects()->attach($projects->modelKeys(), ['is_active' => true, 'role' => 'member']);
        self::assertTrue($this->policy->canReadEntity($this->actor, $this->organization->id, 'project', $projects->first()->id));
        $counts = [];
        foreach ([8, 48] as $size) {
            $refs = $projects->take($size)->map(fn (Project $project): array => $this->reference($project))->all();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                self::assertTrue($this->guard->canRead($this->actor, $this->organization->id, $refs));
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
        self::assertLessThanOrEqual($counts[0] + 3, $counts[1]);
        self::assertLessThan(25, $counts[1]);
    }

    public function test_every_identity_must_be_accessible_and_duplicates_do_not_change_the_result(): void
    {
        $visible = $this->project(true);
        $hidden = $this->project(false);
        $foreign = Project::withoutEvents(fn () => Project::factory()->create());
        self::assertTrue($this->guard->canRead($this->actor, $this->organization->id, [$this->reference($visible), $this->reference($visible)]));
        foreach ([$hidden, $foreign] as $denied) {
            self::assertFalse($this->guard->canRead($this->actor, $this->organization->id, [$this->reference($visible), $this->reference($denied)]));
        }
        self::assertFalse($this->guard->canRead($this->actor, $this->organization->id,
            [$this->reference($visible), ['entity_type' => 'project', 'entity_id' => 999999999]]));
        self::assertFalse($this->guard->canRead($this->actor, $this->organization->id,
            [$this->reference($visible), ['entity_type' => 'unknown', 'entity_id' => $visible->id]]));
        self::assertFalse($this->guard->canRead($this->actor, $this->organization->id,
            [$this->reference($visible) + ['organization_id' => $foreign->organization_id]]));
    }

    public function test_next_message_in_the_same_frame_observes_assignment_revocation_and_deletion(): void
    {
        $project = $this->project(true);
        $reference = $this->reference($project);
        $this->policy->withCurrentChecks($this->actor, $this->organization->id, function () use ($project, $reference): void {
            self::assertTrue($this->guard->canRead($this->actor, $this->organization->id, [$reference], false));
            $this->actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
            self::assertFalse($this->guard->canRead($this->actor, $this->organization->id, [$reference], false));
            $this->actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
            self::assertTrue($this->guard->canRead($this->actor, $this->organization->id, [$reference], false));
            $project->delete();
            self::assertFalse($this->guard->canRead($this->actor, $this->organization->id, [$reference], false));
        }, true);
    }

    public function test_nested_fresh_check_never_uses_an_outer_deferred_entity_grant(): void
    {
        $project = $this->project(true);
        $freshResult = null;
        $this->duringPermission = function (string $permission) use ($project, &$freshResult): void {
            if ($permission !== 'probe.current-row') { return; }
            $this->actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
            $freshResult = $this->policy->withCurrentChecks($this->actor, $this->organization->id,
                fn (): bool => $this->policy->canReadEntity($this->actor, $this->organization->id, 'project', $project->id), true);
        };
        $protected = $this->reference($project) + ['content_scope' => 'structured', 'checked_fields' => ['name'],
            'required_permissions' => ['probe.current-row'], 'required_domains' => ['projects']];
        self::assertFalse($this->guard->canRead($this->actor, $this->organization->id, [$this->reference($project), $protected]));
        self::assertFalse($freshResult);
    }

    public function test_reference_sets_keep_independent_decisions_and_recheck_within_one_frame(): void
    {
        $visible = $this->project(true);
        $hidden = $this->project(false);
        $reference = $this->reference($visible);
        $sets = ['first' => [$reference], 'duplicate' => [$reference, $reference], 'hidden' => [$this->reference($hidden)],
            'mixed' => [$reference, $this->reference($hidden)], 'malformed' => ['invalid'], 'empty' => []];
        $this->policy->withCurrentChecks($this->actor, $this->organization->id, function () use ($visible, $sets): void {
            $read = fn (): array => $this->policy->canReadReferenceSets($this->actor, $this->organization->id, $sets);
            self::assertSame(['first' => true, 'duplicate' => true, 'hidden' => false, 'mixed' => false, 'malformed' => false, 'empty' => true], $read());
            $this->actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
            self::assertFalse($read()['first']);
            $this->actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => true]);
            self::assertTrue($read()['first']);
            $visible->delete();
            self::assertFalse($read()['first']);
        }, fresh: true);
    }

    public function test_reference_provenance_is_checked_before_a_bulk_native_grant(): void
    {
        $project = $this->project(true);
        $reference = $this->reference($project);
        self::assertFalse($this->guard->canRead($this->actor, $this->organization->id, [$reference + ['source_id' => 999999999]]));
        self::assertFalse($this->guard->canRead($this->actor, $this->organization->id,
            [$reference + ['content_scope' => 'structured', 'checked_fields' => [], 'required_permissions' => [], 'required_domains' => ['projects']]]));
        self::assertFalse($this->guard->canRead($this->actor, $this->organization->id, ['invalid']));
    }

    public function test_financial_helpers_receive_live_entity_checks_instead_of_deferred_grants(): void
    {
        $visible = $this->project(true);
        $hidden = $this->project(false);
        $observed = null;
        $reader = new class(function () use ($hidden, &$observed): bool {
            return $observed = $this->policy->canReadEntity($this->actor, $this->organization->id, 'project', $hidden->id);
        }) {
            public int $calls = 0;

            public function __construct(private \Closure $read) {}

            public function matchesReference(User $actor, int $organizationId, array $reference): bool
            {
                $this->calls++;
                return ($this->read)();
            }
        };
        $this->app->instance(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceReader::class, $reader);
        self::assertFalse($this->guard->canRead($this->actor, $this->organization->id, [$this->reference($visible),
            ['entity_type' => 'live_project_financial_projection', 'entity_id' => $hidden->id]]));
        self::assertFalse($observed);
        self::assertSame(1, $reader->calls);
    }

    public function test_exception_cleanup_and_actor_boundaries_do_not_leave_a_deferred_grant(): void
    {
        $project = $this->project(true);
        $this->duringPermission = static function (string $permission): void {
            if ($permission === 'probe.throw') { throw new RuntimeException('reference_batch_probe'); }
        };
        $protected = $this->reference($project) + ['content_scope' => 'structured', 'checked_fields' => ['name'],
            'required_permissions' => ['probe.throw'], 'required_domains' => ['projects']];
        try {
            $this->guard->canRead($this->actor, $this->organization->id, [$this->reference($project), $protected]);
            self::fail('Expected permission probe exception');
        } catch (RuntimeException $exception) {
            self::assertSame('reference_batch_probe', $exception->getMessage());
        }
        $this->duringPermission = null;
        $this->actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'project', $project->id));
        $other = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]));
        $other->organizations()->attach($this->organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        self::assertFalse($this->guard->canRead($other, $this->organization->id, [$this->reference($project)]));
    }

    private function project(bool $assigned): Project
    {
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        if ($assigned) { $this->actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']); }
        return $project;
    }

    private function reference(Project $project): array
    {
        return ['entity_type' => 'project', 'entity_id' => (string) $project->id];
    }
}

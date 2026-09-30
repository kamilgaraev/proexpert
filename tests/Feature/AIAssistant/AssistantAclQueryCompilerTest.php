<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class AssistantAclQueryCompilerTest extends TestCase
{
    use RefreshDatabase;

    private AssistantDataAccessPolicy $policy;
    private Organization $organization;
    private User $actor;
    private Project $visible;
    private Project $hidden;
    private array $deniedPermissions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $user, string $permission): bool => ! in_array($permission, $this->deniedPermissions, true));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('getUserRoles')->andReturn(collect());
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect(['ai-assistant', 'project-management', 'reports', 'budget-estimates', 'contract-management', 'payments', 'procurement', 'site-requests'])
            ->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $this->policy);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]));
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $this->visible = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->actor->assignedProjects()->attach($this->visible->id, ['is_active' => true, 'role' => 'member']);
    }

    public function test_materialized_source_acl_filters_hidden_high_rank_rows_before_limit_and_rechecks_revocation(): void
    {
        $allowed = $this->source($this->visible);
        for ($index = 0; $index < 30; $index++) {
            $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
            $this->source($hidden);
        }
        $query = $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id)->orderByDesc('id')->limit(1);
        $this->assertLessThan(600 * count(AssistantDataAccessPolicy::entityDefinitions()), strlen($query->toSql()));
        $this->assertSame([$allowed->id], $query->pluck('id')->all());
        $this->deniedPermissions = ['projects.view'];
        $this->assertSame([], $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id)->pluck('id')->all());
        $this->deniedPermissions = [];
        $prepared = $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id);
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => false]);
        $this->assertSame([], $prepared->pluck('id')->all());
        $this->assertSame([], $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id)->pluck('id')->all());
    }

    public function test_prepared_acl_rechecks_each_current_actor_and_membership_condition_at_execution(): void
    {
        $allowed = $this->source($this->visible);
        $otherOrganization = Organization::withoutEvents(fn () => Organization::factory()->create());
        foreach (['inactive_user', 'changed_organization', 'inactive_membership'] as $condition) {
            $prepared = $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id);
            $this->assertSame([$allowed->id], (clone $prepared)->pluck('id')->all());
            if ($condition === 'inactive_user') {
                DB::table('users')->where('id', $this->actor->id)->update(['is_active' => false]);
            } elseif ($condition === 'changed_organization') {
                DB::table('users')->where('id', $this->actor->id)->update(['current_organization_id' => $otherOrganization->id]);
            } else {
                $this->actor->organizations()->updateExistingPivot($this->organization->id, ['is_active' => false]);
            }
            $this->assertSame([], $prepared->pluck('id')->all(), $condition);
            DB::table('users')->where('id', $this->actor->id)->update(['is_active' => true, 'current_organization_id' => $this->organization->id]);
            $this->actor->organizations()->updateExistingPivot($this->organization->id, ['is_active' => true]);
        }
    }

    public function test_materialized_reference_scope_requires_every_reference_and_current_project_membership(): void
    {
        $visibleRef = ['entity_type' => 'project', 'entity_id' => (string) $this->visible->id];
        $valid = $this->report([$visibleRef]);
        $this->report([$visibleRef, ['entity_type' => 'project', 'entity_id' => (string) $this->hidden->id]]);
        $this->report([$visibleRef, ['entity_type' => 'unregistered_type', 'entity_id' => '1']]);
        $this->report([$visibleRef, []]);
        $this->report([$visibleRef + ['content_scope' => 'structured', 'checked_fields' => ['budget_amount'], 'required_permissions' => ['finance.view'], 'required_domains' => ['finance']]]);
        $this->report([$visibleRef + ['projection_name' => 'award_candidates', 'composite_key' => ['event_id' => (string) $this->visible->id, 'ordinal' => 1]]]);
        $this->report([$visibleRef + ['source_version' => 'unverified_snapshot']]);
        $this->report([['entity_type' => 'epm_data_mart_snapshot', 'entity_id' => '1']]);
        $invalidDomains = $this->report([$visibleRef]);
        DB::table('project_pulse_reports')->where('id', $invalidDomains->id)->update(['required_domains' => json_encode('projects', JSON_THROW_ON_ERROR)]);
        $invalidReferences = $this->report([$visibleRef]);
        DB::table('project_pulse_reports')->where('id', $invalidReferences->id)->update(['source_refs' => json_encode('invalid', JSON_THROW_ON_ERROR)]);
        $this->deniedPermissions = ['finance.view'];
        $query = $this->policy->entityQuery($this->actor, $this->organization->id, 'project_pulse_report');
        $this->assertNotNull($query);
        $this->assertSame([$valid->id], $query->orderByDesc('id')->limit(1)->pluck('id')->all());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => false]);
        $query = $this->policy->entityQuery($this->actor, $this->organization->id, 'project_pulse_report');
        $this->assertNotNull($query);
        $this->assertSame([], $query->pluck('id')->all());
    }

    private function source(Project $project): RagSource
    {
        return RagSource::withoutEvents(fn (): RagSource => RagSource::create(['organization_id' => $this->organization->id, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id, 'title' => 'Проверка текущего доступа',
            'checksum' => hash('sha256', (string) $project->id), 'indexed_at' => now()]));
    }

    private function report(array $references): ProjectPulseReport
    {
        return ProjectPulseReport::withoutEvents(fn (): ProjectPulseReport => ProjectPulseReport::create(['organization_id' => $this->organization->id,
            'project_id' => $this->visible->id, 'report_date' => today(), 'generated_at' => now(), 'required_domains' => ['projects', 'reports'],
            'source_refs' => $references, 'summary' => [], 'metrics' => [], 'urgent_actions' => [], 'risk_groups' => [], 'finance' => [],
            'activity' => [], 'recommendations' => []]));
    }
}

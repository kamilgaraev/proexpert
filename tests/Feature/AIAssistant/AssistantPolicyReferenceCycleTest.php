<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class AssistantPolicyReferenceCycleTest extends TestCase
{
    private AssistantDataAccessPolicy $policy;
    private Organization $organization;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'workforce-management'], (object) ['slug' => 'project-management']]));
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]));
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
    }

    public function test_department_business_parent_is_nonrecursive_but_rejects_foreign_and_soft_deleted_parent(): void
    {
        $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
        $root = $this->department($this->organization->id, null, 'root');
        $child = $this->department($this->organization->id, $root, 'child');
        $foreignRoot = $this->department($foreign->id, null, 'foreign');
        $forbidden = $this->department($this->organization->id, $foreignRoot, 'forbidden');
        $this->assertTrue($this->policy->entityQuery($this->actor, $this->organization->id, 'workforce_department')->whereKey($child)->exists());
        $this->assertFalse($this->policy->entityQuery($this->actor, $this->organization->id, 'workforce_department')->whereKey($forbidden)->exists());
        DB::table('workforce_departments')->where('id', $root)->update(['deleted_at' => now()]);
        $this->assertFalse($this->policy->entityQuery($this->actor, $this->organization->id, 'workforce_department')->whereKey($child)->exists());
    }

    public function test_previous_export_package_keeps_current_period_project_and_organization_scope(): void
    {
        $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
        $period = $this->period($this->organization->id);
        $old = $this->package($this->organization->id, $period, null, 'old');
        $current = $this->package($this->organization->id, $period, $old, 'current');
        $foreignOld = $this->package($foreign->id, $this->period($foreign->id), null, 'foreign');
        $forbidden = $this->package($this->organization->id, $period, $foreignOld, 'forbidden');
        $this->assertTrue($this->policy->entityQuery($this->actor, $this->organization->id, 'workforce_export_package')->whereKey($current)->exists());
        $this->assertFalse($this->policy->entityQuery($this->actor, $this->organization->id, 'workforce_export_package')->whereKey($forbidden)->exists());
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->actor->organizations()->updateExistingPivot($this->organization->id, ['project_access_mode' => 'assigned_projects']);
        DB::table('workforce_payroll_periods')->where('id', $period)->update(['project_id' => $hidden->id]);
        $this->assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'workforce_export_package', $current));
    }

    public function test_empty_source_scope_finishes_with_self_references_and_keeps_source_query_empty(): void
    {
        $start = microtime(true);
        $query = $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id);
        $this->assertStringContainsString('workforce_departments', $query->toSql());
        $this->assertStringContainsString('workforce_export_packages', $query->toSql());
        $this->assertStringNotContainsString('in (select "workforce_departments"."id",', $query->toSql());
        $this->assertStringNotContainsString('in (select "workforce_export_packages"."id",', $query->toSql());
        $this->assertSame(0, $query->count());
        $this->assertLessThan(10, microtime(true) - $start);
    }

    private function department(int $organizationId, ?int $parentId, string $code): int
    {
        return DB::table('workforce_departments')->insertGetId(['organization_id' => $organizationId, 'parent_id' => $parentId,
            'code' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function period(int $organizationId): int
    {
        return DB::table('workforce_payroll_periods')->insertGetId(['organization_id' => $organizationId,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => 'locked', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function package(int $organizationId, int $periodId, ?int $previousId, string $number): int
    {
        return DB::table('workforce_export_packages')->insertGetId(['organization_id' => $organizationId, 'payroll_period_id' => $periodId,
            'supersedes_package_id' => $previousId, 'package_number' => $number, 'source_hash' => hash('sha256', $number), 'created_at' => now(), 'updated_at' => now()]);
    }
}

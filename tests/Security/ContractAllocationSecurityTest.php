<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractProjectAllocation;
use App\Models\Project;
use App\Services\Contract\ContractAllocationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ContractAllocationSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new \App\Models\Module(['slug' => 'contract-management'])]));
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
    }

    public function test_foreign_and_unrelated_projects_are_rejected_without_deactivating_existing_allocations(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = $this->contract($context, $project);
        Auth::shouldUse('api_admin');
        Auth::setUser($context->user);
        $service = app(ContractAllocationService::class);
        $allocation = $service->syncAllocations($contract, [
            ['project_id' => $project->id, 'allocation_type' => 'fixed', 'allocated_amount' => 100],
        ])->first();
        foreach ([Project::factory()->create(), Project::factory()->create(['organization_id' => $context->organization->id])] as $invalidProject) {
            try {
                $service->syncAllocations($contract, [
                    ['project_id' => $invalidProject->id, 'allocation_type' => 'fixed', 'allocated_amount' => 50],
                ]);
                self::fail('An unrelated project cannot receive a contract allocation.');
            } catch (ValidationException) {
                self::assertTrue($allocation->fresh()->is_active);
                self::assertEquals(100, $allocation->fresh()->allocated_amount);
                self::assertSame(1, ContractProjectAllocation::query()->where('contract_id', $contract->id)->count());
            }
        }
        self::assertSame([$project->id], $service->readableAllocations($contract)->pluck('project_id')->all());
    }

    public function test_admin_viewer_cannot_mutate_contract_allocations_through_http(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = $this->contract($context, $project);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/contracts/'.$contract->id.'/allocations/sync', [
            'allocations' => [['project_id' => $project->id, 'allocation_type' => 'fixed', 'allocated_amount' => 100]],
        ])->assertForbidden();
        self::assertSame(0, ContractProjectAllocation::query()->where('contract_id', $contract->id)->count());
    }

    public function test_partial_project_access_cannot_receive_a_full_contract_allocation_summary(): void
    {
        $context = AdminApiTestContext::create();
        $first = Project::factory()->create(['organization_id' => $context->organization->id]);
        $second = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = $this->contract($context, $first);
        $contract->update(['is_multi_project' => true]);
        $contract->projects()->attach([$first->id, $second->id]);
        Auth::shouldUse('api_admin');
        Auth::setUser($context->user);
        $service = app(ContractAllocationService::class);
        $service->syncAllocations($contract, [
            ['project_id' => $first->id, 'allocation_type' => 'fixed', 'allocated_amount' => 400],
            ['project_id' => $second->id, 'allocation_type' => 'fixed', 'allocated_amount' => 600],
        ]);
        $summary = $service->getAllocationSummary($contract);
        self::assertEquals(1000, $summary['total_allocated']);
        self::assertEquals(0, $summary['unallocated']);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $context->user->assignedProjects()->attach($first->id, ['is_active' => true, 'role' => 'member']);
        self::assertSame([$first->id], $service->readableAllocations($contract)->pluck('project_id')->all());
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/contracts/'.$contract->id.'/allocations/summary')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $service->getAllocationSummary($contract);
    }

    private function contract(AdminApiTestContext $context, Project $project): Contract
    {
        $contractor = Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'Test contractor', 'inn' => '770000000001']);

        return Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'contractor_id' => $contractor->id, 'number' => 'SEC-1', 'date' => '2026-10-14',
            'subject' => 'Works', 'total_amount' => 1000, 'status' => 'active',
        ]);
    }
}

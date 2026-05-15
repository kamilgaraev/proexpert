<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryOperationsMobileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreman_reports_machinery_shift_downtime_and_fuel_without_cross_project_leaks(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'MOB-EXC-1',
            'name' => 'Mobile excavator',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
        $this->allowAccess();

        $assetList = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/machinery-operations/assets?project_id={$project->id}");
        $assetList->assertOk()
            ->assertJsonPath('data.data.0.id', $asset->id)
            ->assertJsonPath('data.data.0.workflow_summary.status', 'in_operation');

        $shift = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'planned_hours' => 8,
                'actual_hours' => 7,
                'fuel_consumed' => 90,
                'work_description' => 'Daily earthworks',
            ]);
        $shift->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $shiftId = (int) $shift->json('data.id');

        $submitted = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/submit");
        $submitted->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $shiftList = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/machinery-operations/shift-reports?project_id={$project->id}");
        $shiftList->assertOk()
            ->assertJsonPath('data.data.0.id', $shiftId)
            ->assertJsonPath('data.data.0.status', 'submitted');

        $downtime = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/downtimes', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'shift_report_id' => $shiftId,
                'reason' => 'operator_waiting',
                'started_at' => now()->subHour()->toIso8601String(),
                'duration_minutes' => 30,
            ]);
        $downtime->assertCreated()
            ->assertJsonPath('data.reason', 'operator_waiting');

        $fuel = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/fuel-issues', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'issued_at' => now()->toIso8601String(),
                'fuel_type' => 'diesel',
                'quantity' => 50,
            ]);
        $fuel->assertCreated()
            ->assertJsonPath('data.fuel_type', 'diesel');

        $foreignShift = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $foreignProject->id,
                'report_date' => now()->toDateString(),
                'actual_hours' => 1,
            ]);
        $foreignShift->assertStatus(422);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'machinery-operations',
                    'project-management',
                ], true)
            );
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryOperationsMobileWorkflowTest extends TestCase
{
    public function test_mobile_rejects_same_organization_shift_link_mismatch(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $shiftAsset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $projectA->id,
            'asset_code' => 'MOBILE-INTEGRITY-1',
            'name' => 'Mobile shift integrity asset',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
        $otherAsset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $projectA->id,
            'asset_code' => 'MOBILE-INTEGRITY-2',
            'name' => 'Mobile operation integrity asset',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
        MachineryAssignment::query()->insert([
            [
                'organization_id' => $context->organization->id,
                'asset_id' => $shiftAsset->id,
                'project_id' => $projectA->id,
                'requested_by_user_id' => $context->user->id,
                'approved_by_user_id' => $context->user->id,
                'status' => 'active',
                'planned_start_at' => now()->subHour(),
                'actual_start_at' => now()->subHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $context->organization->id,
                'asset_id' => $otherAsset->id,
                'project_id' => $projectA->id,
                'requested_by_user_id' => $context->user->id,
                'approved_by_user_id' => $context->user->id,
                'status' => 'active',
                'planned_start_at' => now()->subHour(),
                'actual_start_at' => now()->subHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $shift = MachineryShiftReport::query()->create([
            'organization_id' => $context->organization->id,
            'asset_id' => $shiftAsset->id,
            'project_id' => $projectA->id,
            'reported_by_user_id' => $context->user->id,
            'report_date' => now()->toDateString(),
            'status' => 'draft',
            'planned_hours' => 8,
            'actual_hours' => 8,
            'fuel_consumed' => 10,
        ]);
        $this->allowAccess();

        $downtime = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/downtimes', [
                'asset_id' => $otherAsset->id,
                'project_id' => $projectA->id,
                'shift_report_id' => $shift->id,
                'reason' => 'waiting_material',
                'started_at' => now()->subHour()->toIso8601String(),
                'duration_minutes' => 60,
            ]);
        $downtime->assertStatus(422);

        $production = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/production-records', [
                'asset_id' => $shiftAsset->id,
                'project_id' => $projectB->id,
                'shift_report_id' => $shift->id,
                'recorded_at' => now()->subMinute()->toIso8601String(),
                'quantity' => 10,
                'unit' => 'm3',
            ]);
        $production->assertStatus(422);

        $this->assertDatabaseCount('machinery_downtimes', 0);
        $this->assertDatabaseCount('machinery_production_records', 0);
    }

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
        $this->createActiveAssignment($asset, (int) $project->id, (int) $context->user->id);
        $this->allowAccess();

        $assetList = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/machinery-operations/assets?project_id={$project->id}");
        $assetList->assertOk()
            ->assertJsonPath('data.data.0.id', $asset->id)
            ->assertJsonPath('data.data.0.current_assignment.asset_id', $asset->id)
            ->assertJsonPath('data.data.0.current_assignment.project_id', $project->id)
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
                'unit' => 'l',
            ]);
        $fuel->assertCreated()
            ->assertJsonPath('data.fuel_type', 'diesel');

        $production = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/production-records', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'shift_report_id' => $shiftId,
                'recorded_at' => now()->toIso8601String(),
                'quantity' => 120.5,
                'unit' => 'm3',
                'comment' => 'Excavation output',
            ]);
        $production->assertCreated()
            ->assertJsonPath('data.unit', 'm3');
        self::assertSame(120.5, (float) $production->json('data.quantity'));

        $foreignShift = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $foreignProject->id,
                'report_date' => now()->toDateString(),
                'actual_hours' => 1,
                'fuel_consumed' => 1,
            ]);
        $foreignShift->assertStatus(422);
    }

    public function test_mobile_user_can_record_machine_shift_actuals(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'MOB-DOZ-1',
            'name' => 'Mobile dozer',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1500,
        ]);
        $this->createActiveAssignment($asset, (int) $project->id, (int) $context->user->id);
        $this->allowAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'planned_hours' => 8,
                'actual_hours' => 6.75,
                'fuel_consumed' => 34.5,
                'work_description' => 'Grading work',
            ]);

        $response->assertCreated();
        self::assertSame(6.75, (float) $response->json('data.actual_hours'));
        self::assertSame(34.5, (float) $response->json('data.fuel_consumed'));

        $this->assertDatabaseHas('machinery_shift_reports', [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'actual_hours' => 6.75,
            'fuel_consumed' => 34.5,
        ]);
    }

    public function test_operator_shift_lifecycle_is_idempotent_across_offline_retries(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'MOB-IDEMPOTENT-1',
            'name' => 'Idempotent mobile excavator',
            'status' => 'assigned',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1200,
        ]);
        $this->createActiveAssignment($asset, (int) $project->id, (int) $context->user->id);
        $this->allowAccess();

        $payload = [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'report_date' => now()->toDateString(),
            'planned_hours' => 8,
            'actual_hours' => 0,
            'fuel_consumed' => 0,
            'meter_start' => 150,
        ];
        $first = $this->withHeaders([...$context->authHeaders(), 'Idempotency-Key' => 'operator-shift-start-1'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', $payload)
            ->assertCreated();
        $repeated = $this->withHeaders([...$context->authHeaders(), 'Idempotency-Key' => 'operator-shift-start-1'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', $payload)
            ->assertCreated();
        self::assertSame($first->json('data.id'), $repeated->json('data.id'));
        $shiftId = (int) $first->json('data.id');
        $this->assertDatabaseCount('machinery_shift_reports', 1);

        $finishPayload = ['actual_hours' => 7.5, 'fuel_consumed' => 45, 'meter_end' => 157.5];
        $this->withHeaders([...$context->authHeaders(), 'Idempotency-Key' => 'operator-shift-finish-1'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", $finishPayload)
            ->assertOk()
            ->assertJsonPath('data.actual_hours', '7.50');
        $this->withHeaders([...$context->authHeaders(), 'Idempotency-Key' => 'operator-shift-finish-1'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", $finishPayload)
            ->assertOk();

        $this->withHeaders([...$context->authHeaders(), 'Idempotency-Key' => 'operator-shift-submit-1'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->withHeaders([...$context->authHeaders(), 'Idempotency-Key' => 'operator-shift-submit-1'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->withHeaders([...$context->authHeaders(), 'Idempotency-Key' => 'operator-shift-start-1'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [...$payload, 'meter_start' => 151])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('machinery_operations.errors.idempotency_conflict'));
    }

    public function test_mobile_machine_actuals_require_real_values(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'MOB-GRD-1',
            'name' => 'Mobile grader',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1700,
        ]);
        $this->allowAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->addDay()->toDateString(),
                'fuel_consumed' => -1,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', trans_message('machinery_operations.errors.validation_failed'))
            ->assertJsonPath('errors.actual_hours.0', trans_message('machinery_operations.validation.actual_hours_required'))
            ->assertJsonPath('errors.fuel_consumed.0', trans_message('machinery_operations.validation.fuel_consumed_min'))
            ->assertJsonPath('errors.report_date.0', trans_message('machinery_operations.validation.date_future'));

        $this->assertDatabaseMissing('machinery_shift_reports', [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_mobile_machine_downtime_requires_reason_and_duration(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'MOB-CRN-1',
            'name' => 'Mobile crane',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 2200,
        ]);
        $shift = MachineryShiftReport::query()->create([
            'organization_id' => $context->organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'reported_by_user_id' => $context->user->id,
            'report_date' => now()->toDateString(),
            'status' => 'draft',
            'planned_hours' => 8,
            'actual_hours' => 4,
            'fuel_consumed' => 20,
        ]);
        $this->allowAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/machinery-operations/downtimes', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'shift_report_id' => $shift->id,
                'started_at' => now()->toIso8601String(),
                'duration_minutes' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', trans_message('machinery_operations.errors.validation_failed'))
            ->assertJsonPath('errors.reason.0', trans_message('machinery_operations.validation.downtime_reason_required'))
            ->assertJsonPath('errors.duration_minutes.0', trans_message('machinery_operations.validation.duration_positive'));

        $this->assertDatabaseMissing('machinery_downtimes', [
            'asset_id' => $asset->id,
            'shift_report_id' => $shift->id,
        ]);
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

    private function createActiveAssignment(MachineryAsset $asset, int $projectId, int $userId): void
    {
        MachineryAssignment::query()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'project_id' => $projectId,
            'requested_by_user_id' => $userId,
            'approved_by_user_id' => $userId,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ]);
    }
}

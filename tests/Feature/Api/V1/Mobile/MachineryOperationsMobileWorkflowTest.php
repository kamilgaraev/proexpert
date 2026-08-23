<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginCalculator;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportService;
use App\BusinessModules\Features\Budgeting\Services\WipForecastCalculator;
use App\BusinessModules\Features\Budgeting\Services\WipForecastReportService;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Gate;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\Support\AdminApiTestContext;
use Tests\Support\MachineryOperationsAssetFactory;
use Tests\TestCase;

final class MachineryOperationsMobileWorkflowTest extends TestCase
{
    public function test_mobile_rejects_same_organization_shift_link_mismatch(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
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

        $downtime = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'foreman-downtime'])
            ->postJson('/api/v1/mobile/machinery-operations/downtimes', [
                'asset_id' => $otherAsset->id,
                'project_id' => $projectA->id,
                'shift_report_id' => $shift->id,
                'reason' => 'waiting_material',
                'started_at' => now()->subHour()->toIso8601String(),
                'duration_minutes' => 60,
            ]);
        $downtime->assertStatus(422);

        $production = $this->withHeaders($context->mobileAuthHeaders())
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
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'machine_operator');
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

        $assetList = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson("/api/v1/mobile/machinery-operations/assets?project_id={$project->id}");
        $assetList->assertOk()
            ->assertJsonPath('data.data.0.id', $asset->id)
            ->assertJsonPath('data.data.0.current_assignment.asset_id', $asset->id)
            ->assertJsonPath('data.data.0.current_assignment.project_id', $project->id)
            ->assertJsonPath('data.data.0.workflow_summary.status', 'in_operation');

        $shift = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'foreman-shift-start'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'planned_hours' => 8,
                'actual_hours' => 0,
                'fuel_consumed' => 0,
                'meter_start' => 100,
                'pre_shift_inspection' => $this->serviceableInspection(),
            ]);
        $shift->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $shiftId = (int) $shift->json('data.id');

        $downtime = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'foreman-shift-downtime'])
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

        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Foreman fuel warehouse',
            'code' => 'FOREMAN-FUEL',
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Foreman diesel fuel',
            'code' => 'FOREMAN-DIESEL',
            'is_active' => true,
        ]);
        app(WarehouseService::class)->receiveAsset(
            (int) $context->organization->id,
            (int) $warehouse->id,
            (int) $material->id,
            100,
            70,
        );
        $fuel = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'foreman-fuel-once'])
            ->postJson('/api/v1/mobile/machinery-operations/fuel-issues', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'shift_report_id' => $shiftId,
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'issued_at' => now()->toIso8601String(),
                'fuel_type' => 'diesel',
                'quantity' => 50,
                'unit' => 'l',
            ]);
        $fuel->assertCreated()
            ->assertJsonPath('data.fuel_type', 'diesel');

        $production = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'foreman-production'])
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

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'foreman-shift-finish'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", [
                'actual_hours' => 7,
                'fuel_consumed' => 50,
                'meter_end' => 107,
                'work_description' => 'Daily earthworks',
                'post_shift_inspection' => $this->serviceableInspection(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $submitted = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'foreman-shift-submit'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/submit");
        $submitted->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $shiftList = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson("/api/v1/mobile/machinery-operations/shift-reports?project_id={$project->id}");
        $shiftList->assertOk()
            ->assertJsonPath('data.data.0.id', $shiftId)
            ->assertJsonPath('data.data.0.status', 'submitted');

        $foreignShift = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'foreign-shift-start'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $foreignProject->id,
                'report_date' => now()->toDateString(),
                'pre_shift_inspection' => $this->serviceableInspection(),
                'actual_hours' => 1,
                'fuel_consumed' => 1,
            ]);
        $foreignShift->assertStatus(422);
    }

    public function test_mobile_user_can_record_machine_shift_actuals(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
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

        $response = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'actuals-start'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'planned_hours' => 8,
                'actual_hours' => 0,
                'fuel_consumed' => 0,
                'meter_start' => 10,
                'pre_shift_inspection' => $this->serviceableInspection(),
            ]);

        $response->assertCreated();
        $shiftId = (int) $response->json('data.id');
        $finished = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'actuals-finish'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", [
                'actual_hours' => 1,
                'fuel_consumed' => 34.5,
                'meter_end' => 16.75,
                'work_description' => 'Grading work',
                'post_shift_inspection' => $this->serviceableInspection(),
            ])
            ->assertOk();
        self::assertSame(6.75, (float) $finished->json('data.actual_hours'));
        self::assertSame(34.5, (float) $finished->json('data.fuel_consumed'));

        $this->assertDatabaseHas('machinery_shift_reports', [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'actual_hours' => 6.75,
            'fuel_consumed' => 34.5,
        ]);
    }

    public function test_operator_shift_lifecycle_is_idempotent_across_offline_retries(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
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
            'pre_shift_inspection' => $this->serviceableInspection(),
            'planned_hours' => 8,
            'actual_hours' => 0,
            'fuel_consumed' => 0,
            'meter_start' => 150,
        ];
        $first = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'operator-shift-start-1'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', $payload)
            ->assertCreated();
        $repeated = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'operator-shift-start-1'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', $payload)
            ->assertCreated();
        self::assertSame($first->json('data.id'), $repeated->json('data.id'));
        $shiftId = (int) $first->json('data.id');
        $this->assertDatabaseCount('machinery_shift_reports', 1);

        $finishPayload = [
            'actual_hours' => 7.5,
            'fuel_consumed' => 45,
            'meter_end' => 157.5,
            'post_shift_inspection' => $this->serviceableInspection(),
        ];
        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'operator-shift-finish-1'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", $finishPayload)
            ->assertOk()
            ->assertJsonPath('data.actual_hours', '7.50');
        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'operator-shift-finish-1'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", $finishPayload)
            ->assertOk();

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'operator-shift-submit-1'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'operator-shift-submit-1'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'operator-shift-start-1'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [...$payload, 'meter_start' => 151])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('machinery_operations.errors.idempotency_conflict'));
    }

    public function test_different_commands_cannot_start_two_open_shifts_for_one_asset(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'MOB-OPEN-SHIFT-1',
            'name' => 'Single active shift excavator',
            'current_project_id' => $project->id,
            'meter_hours' => 100,
        ]);
        $this->createActiveAssignment($asset, (int) $project->id, (int) $context->user->id);

        $payload = [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'report_date' => now()->toDateString(),
            'pre_shift_inspection' => $this->serviceableInspection(),
            'actual_hours' => 0,
            'fuel_consumed' => 0,
            'meter_start' => 100,
        ];

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'open-shift-a'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', $payload)
            ->assertCreated();

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'open-shift-b'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('machinery_operations.errors.shift_already_open'));

        $this->assertDatabaseCount('machinery_shift_reports', 1);
    }

    public function test_finish_is_terminal_and_actual_hours_are_derived_from_meter_delta(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'MOB-FINISH-ONCE-1',
            'name' => 'Terminal finish excavator',
            'current_project_id' => $project->id,
            'meter_hours' => 200,
        ]);
        $this->createActiveAssignment($asset, (int) $project->id, (int) $context->user->id);

        $shift = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'finish-once-start'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'pre_shift_inspection' => $this->serviceableInspection(),
                'actual_hours' => 0,
                'fuel_consumed' => 0,
                'meter_start' => 200,
            ])
            ->assertCreated();
        $shiftId = (int) $shift->json('data.id');

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'finish-once-command-a'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", [
                'actual_hours' => 1,
                'fuel_consumed' => 35,
                'meter_end' => 208.25,
                'post_shift_inspection' => $this->serviceableInspection(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.actual_hours', '8.25');

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'finish-once-command-b'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", [
                'actual_hours' => 1,
                'fuel_consumed' => 99,
                'meter_end' => 299,
                'post_shift_inspection' => $this->serviceableInspection(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('machinery_operations.errors.shift_finish_invalid_status'));

        $this->assertDatabaseHas('machinery_shift_reports', [
            'id' => $shiftId,
            'status' => 'completed',
            'actual_hours' => 8.25,
            'fuel_consumed' => 35,
            'meter_end' => 208.25,
        ]);
    }

    public function test_fuel_retry_creates_one_warehouse_movement_and_server_cost(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'MOB-FUEL-ONCE-1',
            'name' => 'Fuel idempotency excavator',
            'current_project_id' => $project->id,
            'fuel_type' => 'diesel',
            'metadata' => ['fuel_capacity' => 100],
        ]);
        $this->createActiveAssignment($asset, (int) $project->id, (int) $context->user->id);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Fuel test warehouse',
            'code' => 'FUEL-TEST',
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Diesel fuel',
            'code' => 'DIESEL-TEST',
            'is_active' => true,
        ]);
        app(WarehouseService::class)->receiveAsset(
            (int) $context->organization->id,
            (int) $warehouse->id,
            (int) $material->id,
            200,
            75.25,
        );

        $shift = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'fuel-once-start'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'pre_shift_inspection' => $this->serviceableInspection(),
                'actual_hours' => 0,
                'fuel_consumed' => 0,
            ])
            ->assertCreated();
        $payload = [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'shift_report_id' => (int) $shift->json('data.id'),
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'issued_at' => now()->toIso8601String(),
            'fuel_type' => 'diesel',
            'quantity' => 40,
            'unit' => 'l',
        ];

        $first = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'fuel-once-command'])
            ->postJson('/api/v1/mobile/machinery-operations/fuel-issues', $payload)
            ->assertCreated()
            ->assertJsonPath('data.cost', '3010.00');
        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'fuel-once-command'])
            ->postJson('/api/v1/mobile/machinery-operations/fuel-issues', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('machinery_fuel_issues', 1);
        self::assertSame(1, \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::query()
            ->where('movement_type', 'write_off')
            ->count());
    }

    public function test_project_margin_counts_shift_and_fuel_cost_exactly_once(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'MOB-MARGIN-ONCE-1',
            'name' => 'Margin cost excavator',
            'current_project_id' => $project->id,
            'status' => 'in_operation',
            'operating_cost_per_hour' => 1200,
            'fuel_type' => 'diesel',
            'metadata' => ['fuel_capacity' => 100],
        ]);
        $this->createActiveAssignment($asset, (int) $project->id, (int) $context->user->id);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Margin fuel warehouse',
            'code' => 'MARGIN-FUEL',
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Margin diesel fuel',
            'code' => 'MARGIN-DIESEL',
            'is_active' => true,
        ]);
        app(WarehouseService::class)->receiveAsset(
            (int) $context->organization->id,
            (int) $warehouse->id,
            (int) $material->id,
            100,
            75.25,
        );

        $shift = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'margin-shift-start'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'meter_start' => 100,
                'actual_hours' => 0,
                'fuel_consumed' => 0,
                'pre_shift_inspection' => $this->serviceableInspection(),
            ])
            ->assertCreated();
        $shiftId = (int) $shift->json('data.id');

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'margin-fuel'])
            ->postJson('/api/v1/mobile/machinery-operations/fuel-issues', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'shift_report_id' => $shiftId,
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'issued_at' => now()->toIso8601String(),
                'fuel_type' => 'diesel',
                'quantity' => 10.005,
                'unit' => 'l',
            ])
            ->assertCreated()
            ->assertJsonPath('data.cost', '752.88');

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'margin-shift-finish'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/finish", [
                'actual_hours' => 1,
                'fuel_consumed' => 10.005,
                'meter_end' => 105,
                'post_shift_inspection' => $this->serviceableInspection(),
            ])
            ->assertOk();
        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'margin-shift-submit'])
            ->postJson("/api/v1/mobile/machinery-operations/shift-reports/{$shiftId}/submit")
            ->assertOk();
        app(MachineryOperationsService::class)->approveShift(
            MachineryShiftReport::query()->findOrFail($shiftId),
            (int) $context->user->id,
        );

        $filters = new ProjectMarginReportFilters(
            organizationId: (int) $context->organization->id,
            periodStart: now()->toDateString(),
            periodEnd: now()->toDateString(),
            budgetVersionId: null,
            budgetVersionUuid: null,
            scenarioId: null,
            scenarioUuid: null,
            projectId: (int) $project->id,
            projectIds: [(int) $project->id],
            contractId: null,
            responsibilityCenterId: null,
            responsibilityCenterUuid: null,
            budgetArticleId: null,
            budgetArticleUuid: null,
            counterpartyId: null,
            currency: 'RUB',
            groupBy: ['month', 'project', 'currency'],
        );
        $reportService = new ProjectMarginReportService(
            new ProjectMarginCalculator,
            app(AuthorizationService::class),
        );
        $method = new ReflectionMethod(ProjectMarginReportService::class, 'sourceRowsQuery');
        $rows = $method->invoke($reportService, $filters)
            ->get()
            ->filter(static fn (object $row): bool => in_array($row->source_type, [
                'machinery_shift',
                'warehouse_movement',
            ], true))
            ->values();

        $sourceCounts = $rows->countBy('source_type');
        self::assertSame(1, $sourceCounts->get('machinery_shift'));
        self::assertSame(1, $sourceCounts->get('warehouse_movement'));
        self::assertSame(6752.88, $rows->sum(static fn (object $row): float => (float) $row->management_amount));

        $wipFilters = new WipForecastReportFilters(
            organizationId: (int) $context->organization->id,
            periodStart: now()->toDateString(),
            periodEnd: now()->toDateString(),
            asOfDate: now()->toDateString(),
            forecastVersionId: null,
            forecastVersionUuid: null,
            budgetVersionId: null,
            budgetVersionUuid: null,
            scenarioId: null,
            scenarioUuid: null,
            projectId: (int) $project->id,
            stageId: null,
            contractId: null,
            estimateItemId: null,
            currency: 'RUB',
            groupBy: [
                WipForecastReportFilters::GROUP_PERIOD,
                WipForecastReportFilters::GROUP_PROJECT,
                WipForecastReportFilters::GROUP_CURRENCY,
            ],
        );
        $wipService = new WipForecastReportService(
            new WipForecastCalculator,
            app(AuthorizationService::class),
        );
        $wipMethod = new ReflectionMethod(WipForecastReportService::class, 'sourceRowsQuery');
        $wipRows = $wipMethod->invoke($wipService, $wipFilters)
            ->get()
            ->filter(static fn (object $row): bool => in_array($row->source_type, [
                'machinery_shift',
                'warehouse_movement',
            ], true));
        self::assertCount(2, $wipRows);
        self::assertSame(6752.88, $wipRows->sum(static fn (object $row): float => (float) $row->actual_cost_accrual));

        app(MachineryOperationsService::class)->cancelShift(
            MachineryShiftReport::query()->findOrFail($shiftId),
            (int) $context->user->id,
            'Повторно созданная смена',
        );
        app(MachineryOperationsService::class)->cancelShift(
            MachineryShiftReport::query()->findOrFail($shiftId),
            (int) $context->user->id,
            'Повторно созданная смена',
        );

        $rowsAfterCancellation = $method->invoke($reportService, $filters)
            ->get()
            ->filter(static fn (object $row): bool => in_array($row->source_type, [
                'machinery_shift',
                'warehouse_movement',
            ], true));
        self::assertCount(0, $rowsAfterCancellation);
        self::assertCount(0, $wipMethod->invoke($wipService, $wipFilters)
            ->get()
            ->filter(static fn (object $row): bool => in_array($row->source_type, [
                'machinery_shift',
                'warehouse_movement',
            ], true)));
        self::assertSame(100.0, (float) \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->sum('available_quantity'));
        $this->assertDatabaseHas('machinery_fuel_issues', [
            'shift_report_id' => $shiftId,
            'cancellation_reason' => 'Повторно созданная смена',
        ]);
        self::assertSame(1, \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::query()
            ->where('reason', 'machinery_fuel_reversal')
            ->count());
    }

    public function test_blocking_pre_shift_inspection_preserves_defect_and_prevents_operation(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'MOB-BLOCKED-INSPECTION-1',
            'name' => 'Blocked inspection excavator',
            'current_project_id' => $project->id,
        ]);
        $this->createActiveAssignment($asset, (int) $project->id, (int) $context->user->id);

        $shift = $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'blocked-inspection-start'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'actual_hours' => 0,
                'fuel_consumed' => 0,
                'pre_shift_inspection' => [
                    'result' => 'unavailable',
                    'notes' => 'Hydraulic leak detected',
                    'defects' => [[
                        'code' => 'hydraulic_leak',
                        'severity' => 'critical',
                        'description' => 'Hydraulic system loses fluid under pressure',
                    ]],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.inspections.0.inspection_type', 'pre_shift')
            ->assertJsonPath('data.problem_flags.0.code', 'pre_shift_inspection_blocked');

        $this->assertDatabaseHas('machinery_assets', ['id' => $asset->id, 'status' => 'unavailable']);
        $this->assertDatabaseHas('machinery_defects', [
            'shift_report_id' => (int) $shift->json('data.id'),
            'defect_code' => 'hydraulic_leak',
            'severity' => 'critical',
            'status' => 'open',
        ]);
        $this->assertDatabaseMissing('machinery_shift_reports', [
            'id' => (int) $shift->json('data.id'),
            'status' => 'draft',
        ]);
    }

    public function test_mobile_machine_actuals_require_real_values(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
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

        $response = $this->withHeaders($context->mobileAuthHeaders())
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
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
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

        $response = $this->withHeaders($context->mobileAuthHeaders())
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
        $this->withoutMiddleware(AuthorizeMiddleware::class);
        Gate::define('access-mobile-app', static fn (): bool => true);

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

    private function serviceableInspection(): array
    {
        return [
            'result' => 'serviceable',
            'evidence' => ['source' => 'automated_regression'],
            'defects' => [],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Core\AssetManagement\Enums\AssetAccountingMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use Tests\Support\MobileProjectRoleTestContext;
use Tests\TestCase;

final class MachineryOperationsCanonicalAssetTest extends TestCase
{
    public function test_canonical_ineligible_state_blocks_shift_even_when_legacy_asset_is_operating(): void
    {
        $context = MobileProjectRoleTestContext::create('machine_operator');
        $project = $context->project;
        $context->activatePackages(['machinery']);
        $canonical = OrganizationAsset::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Unavailable canonical roller',
            'inventory_number' => 'MOB-CAN-UNAVAILABLE',
            'accounting_mode' => AssetAccountingMode::Serialized,
            'ownership_type' => 'owned',
            'lifecycle_status' => AssetLifecycleStatus::Active,
            'technical_status' => AssetTechnicalStatus::Unavailable,
            'current_project_id' => $project->id,
            'metadata' => ['machinery_operation_status' => 'in_operation'],
        ]);
        $legacy = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'organization_asset_id' => $canonical->id,
            'current_project_id' => $project->id,
            'asset_code' => 'MOB-CAN-UNAVAILABLE',
            'name' => 'Legacy operating status',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
        MachineryAssignment::query()->create([
            'organization_id' => $context->organization->id,
            'organization_asset_id' => $canonical->id,
            'asset_id' => $legacy->id,
            'project_id' => $project->id,
            'requested_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ]);

        $response = $this->withHeaders($context->headers())
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
                'asset_id' => $legacy->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'actual_hours' => 4,
                'fuel_consumed' => 20,
                'pre_shift_inspection' => [
                    'result' => 'serviceable',
                    'evidence' => ['source' => 'automated_regression'],
                    'defects' => [],
                ],
            ]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent());
        $response->assertJsonPath('message', trans_message('machinery_operations.errors.shift_asset_not_operational'));
        $this->assertDatabaseMissing('machinery_shift_reports', ['asset_id' => $legacy->id]);
        $this->assertDatabaseHas('machinery_assets', ['id' => $legacy->id, 'status' => 'in_operation']);
        $this->assertDatabaseHas('organization_assets', [
            'id' => $canonical->id,
            'technical_status' => AssetTechnicalStatus::Unavailable->value,
        ]);
    }

    public function test_mobile_payload_reads_canonical_fields_and_writes_canonical_shift_link(): void
    {
        $context = MobileProjectRoleTestContext::create('machine_operator');
        $project = $context->project;
        $context->activatePackages(['machinery']);
        $canonical = OrganizationAsset::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Canonical mobile roller',
            'inventory_number' => 'MOB-CAN-1',
            'accounting_mode' => AssetAccountingMode::Serialized,
            'ownership_type' => 'owned',
            'lifecycle_status' => AssetLifecycleStatus::Active,
            'technical_status' => AssetTechnicalStatus::Serviceable,
            'current_project_id' => $project->id,
            'metadata' => ['machinery_operation_status' => 'in_operation'],
        ]);
        $legacy = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'organization_asset_id' => $canonical->id,
            'current_project_id' => $project->id,
            'asset_code' => 'MOB-LEG-1',
            'name' => 'Stale legacy name',
            'status' => 'available',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
        MachineryAssignment::query()->create([
            'organization_id' => $context->organization->id,
            'organization_asset_id' => $canonical->id,
            'asset_id' => $legacy->id,
            'project_id' => $project->id,
            'requested_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ]);
        $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/machinery-operations/assets?project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $legacy->id)
            ->assertJsonPath('data.data.0.organization_asset_id', $canonical->id)
            ->assertJsonPath('data.data.0.name', 'Canonical mobile roller')
            ->assertJsonPath('data.data.0.status', 'in_operation');

        $shift = $this->withHeaders($context->headers())->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
            'asset_id' => $legacy->id,
            'project_id' => $project->id,
            'report_date' => now()->toDateString(),
            'actual_hours' => 4,
            'fuel_consumed' => 20,
            'pre_shift_inspection' => [
                'result' => 'serviceable',
                'evidence' => ['source' => 'automated_regression'],
                'defects' => [],
            ],
        ]);
        self::assertSame(201, $shift->getStatusCode(), $shift->getContent());
        $shift->assertJsonPath('data.organization_asset_id', $canonical->id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Core\AssetManagement\Enums\AssetAccountingMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryOperationsCanonicalAssetTest extends TestCase
{
    public function test_mobile_payload_reads_canonical_fields_and_writes_canonical_shift_link(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
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
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/machinery-operations/assets?project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $legacy->id)
            ->assertJsonPath('data.data.0.organization_asset_id', $canonical->id)
            ->assertJsonPath('data.data.0.name', 'Canonical mobile roller')
            ->assertJsonPath('data.data.0.status', 'in_operation');

        $shift = $this->withHeaders($context->authHeaders())->postJson('/api/v1/mobile/machinery-operations/shift-reports', [
            'asset_id' => $legacy->id,
            'project_id' => $project->id,
            'report_date' => now()->toDateString(),
            'actual_hours' => 4,
            'fuel_consumed' => 20,
        ]);
        $shift->assertCreated()->assertJsonPath('data.organization_asset_id', $canonical->id);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, fn (MockInterface $mock) => $mock->shouldReceive('hasModuleAccess')->andReturn(true));
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $context = null) => $user->roleAssignments()->where('is_active', true)->get(),
            );
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDefect;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetReadRepository;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\WebInterfaceSecurityMiddleware;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\Support\MachineryOperationsAssetFactory;
use Tests\TestCase;

final class MachineryOperationsCanonicalAssetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(WebInterfaceSecurityMiddleware::class);
    }

    public function test_admin_workflow_writes_authoritative_canonical_state_and_shadow_links(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();

        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'CAN-EXC-1',
            'name' => 'Canonical excavator',
            'inventory_number' => 'CAN-INV-1',
            'ownership_type' => 'owned',
            'fuel_type' => 'diesel',
        ]);
        $legacyId = (int) $asset->id;
        $canonicalId = (int) $asset->organization_asset_id;
        self::assertGreaterThan(0, $canonicalId);

        MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'CAN-OTHER-2',
            'name' => 'Other asset',
            'inventory_number' => 'OTHER-INV-2',
        ]);
        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery-operations/assets?search=can-inv-1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $legacyId);

        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/machinery-operations/assets/{$legacyId}/assign", [
            'project_id' => $project->id,
            'planned_start_at' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertDatabaseHas('machinery_assignments', [
            'asset_id' => $legacyId,
            'organization_asset_id' => $canonicalId,
        ]);
        $canonical = OrganizationAsset::query()->findOrFail($canonicalId);
        self::assertSame((int) $project->id, (int) $canonical->current_project_id);
        self::assertSame('assigned', $canonical->metadata['machinery_operation_status']);

        $maintenance = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/maintenance-orders', [
            'asset_id' => $legacyId,
            'title' => 'Control service',
        ])->assertCreated();
        self::assertSame(AssetTechnicalStatus::Maintenance, $canonical->refresh()->technical_status);

        $this->withHeaders($context->authHeaders())->postJson(
            '/api/v1/admin/machinery-operations/maintenance-orders/'.$maintenance->json('data.id').'/complete',
            ['completion_comment' => 'Контрольный осмотр пройден'],
        )->assertOk();

        $canonical->refresh();
        self::assertSame(AssetTechnicalStatus::Serviceable, $canonical->technical_status);
        self::assertSame('serviceable', $canonical->metadata['last_control_inspection']['result']);
    }

    public function test_cutover_flag_hides_unlinked_rows(): void
    {
        $context = AdminApiTestContext::create();
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();
        MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'asset_code' => 'UNLINKED-LEGACY',
            'name' => 'Legacy only',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 0,
            'meter_hours' => 0,
        ]);
        config()->set('asset_registry.strict_canonical_reads', true);

        self::assertSame(0, app(MachineryAssetReadRepository::class)
            ->paginate((int) $context->organization->id, 20)
            ->total());

    }

    public function test_legacy_physical_asset_create_route_is_removed(): void
    {
        $context = AdminApiTestContext::create();
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/assets', [
                'asset_code' => 'REMOVED',
                'name' => 'Removed legacy create',
            ])
            ->assertStatus(405);
    }

    public function test_strict_canonical_reads_reject_direct_access_without_a_live_canonical_asset(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $unlinked = MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'asset_code' => 'STRICT-UNLINKED',
            'name' => 'Legacy only',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 0,
            'meter_hours' => 0,
        ]);
        $retired = MachineryOperationsAssetFactory::create($organizationId, [
            'asset_code' => 'STRICT-RETIRED',
            'name' => 'Retired canonical asset',
        ]);
        $retired->organizationAsset()->firstOrFail()->delete();
        config()->set('asset_registry.strict_canonical_reads', true);
        $repository = app(MachineryAssetReadRepository::class);

        self::assertNull($repository->find($organizationId, (int) $unlinked->id));
        self::assertNull($repository->find($organizationId, (int) $retired->id));
    }

    public function test_strict_canonical_reads_hide_orphaned_operational_records_from_scopes_and_reports(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId]);
        $asset = MachineryOperationsAssetFactory::create($organizationId, [
            'asset_code' => 'STRICT-OPERATIONS',
            'name' => 'Strict operations asset',
            'current_project_id' => $project->id,
        ]);
        $canonicalId = (int) $asset->organization_asset_id;

        MachineryAssignment::query()->create([
            'organization_id' => $organizationId,
            'organization_asset_id' => $canonicalId,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
        ]);
        $shift = MachineryShiftReport::query()->create([
            'organization_id' => $organizationId,
            'organization_asset_id' => $canonicalId,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'report_date' => now()->toDateString(),
            'status' => 'approved',
            'planned_hours' => 2,
            'actual_hours' => 3,
            'fuel_consumed' => 4,
            'hourly_rate_snapshot' => 100,
            'approved_at' => now(),
        ]);
        MachineryDowntime::query()->create([
            'organization_id' => $organizationId,
            'organization_asset_id' => $canonicalId,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'shift_report_id' => $shift->id,
            'reason' => 'repair',
            'started_at' => now()->subHour(),
            'duration_minutes' => 60,
        ]);
        MachineryFuelIssue::query()->create([
            'organization_id' => $organizationId,
            'organization_asset_id' => $canonicalId,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'issued_at' => now(),
            'fuel_type' => 'diesel',
            'quantity' => 10,
            'unit' => 'l',
            'cost' => 500,
        ]);
        MachineryMaintenanceOrder::query()->create([
            'organization_id' => $organizationId,
            'organization_asset_id' => $canonicalId,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'order_number' => 'STRICT-MO-1',
            'title' => 'Overdue maintenance',
            'status' => 'open',
            'planned_at' => now()->subDay(),
        ]);
        MachineryProductionRecord::query()->create([
            'organization_id' => $organizationId,
            'organization_asset_id' => $canonicalId,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'shift_report_id' => $shift->id,
            'recorded_at' => now(),
            'quantity' => 1,
            'unit' => 'unit',
        ]);
        MachineryDefect::query()->create([
            'organization_id' => $organizationId,
            'organization_asset_id' => $canonicalId,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'defect_code' => 'STRICT-DEFECT',
            'severity' => 'minor',
            'status' => 'open',
            'description' => 'Orphaned defect',
            'reported_at' => now(),
        ]);

        $asset->organizationAsset()->firstOrFail()->delete();
        config()->set('asset_registry.strict_canonical_reads', true);

        foreach ([
            MachineryAsset::class,
            MachineryAssignment::class,
            MachineryShiftReport::class,
            MachineryDowntime::class,
            MachineryFuelIssue::class,
            MachineryMaintenanceOrder::class,
            MachineryProductionRecord::class,
            MachineryDefect::class,
        ] as $model) {
            self::assertSame(0, $model::forOrganization($organizationId)->count(), $model);
        }

        $operations = app(MachineryOperationsService::class);
        self::assertSame(0, $operations->paginateShifts($organizationId)->total());
        self::assertNull($operations->findShift($organizationId, (int) $shift->id));
        self::assertSame([], $operations->reports($organizationId)['operating_cost_by_project']);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, fn (MockInterface $mock) => $mock->shouldReceive('hasModuleAccess')->andReturn(true));
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $context = null) => $user->roleAssignments()->where('is_active', true)->get(),
            );
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDefect;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MaintenanceInspection;
use App\Models\Material;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class BackfillOrganizationAssetsTest extends TestCase
{
    public function test_dry_run_reports_work_without_writing_any_data(): void
    {
        $context = AdminApiTestContext::create();
        $legacy = $this->createMachineryAsset((int) $context->organization->id, 'BF-DRY', 'INV-BF-DRY');

        $exitCode = Artisan::call('assets:backfill', ['--dry-run' => true, '--format' => 'json']);
        $report = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertTrue($report['dry_run']);
        self::assertSame(1, $report['scanned']);
        self::assertSame(1, $report['would_create']);
        self::assertSame(0, $report['created']);
        self::assertSame(0, $report['conflicts']);
        self::assertSame(0, OrganizationAsset::query()->count());
        self::assertNull($legacy->fresh()->organization_asset_id);
    }

    public function test_two_apply_runs_create_one_asset_and_propagate_shadow_links(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $legacy = $this->createMachineryAsset((int) $context->organization->id, 'BF-IDEMPOTENT', 'INV-BF-IDEMPOTENT');
        $legacy->update(['metadata' => ['rental_terms' => ['daily_rate' => 7500, 'version' => 'legacy-v3']]]);
        $assignment = MachineryAssignment::query()->create([
            'organization_id' => $context->organization->id,
            'asset_id' => $legacy->id,
            'project_id' => $project->id,
            'requested_by_user_id' => $context->user->id,
            'status' => 'active',
            'planned_start_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));
        $firstReport = $this->jsonOutput();
        self::assertSame(1, $firstReport['created']);
        self::assertSame(2, $firstReport['links_updated']);

        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));
        $secondReport = $this->jsonOutput();
        self::assertSame(0, $secondReport['created']);
        self::assertSame(0, $secondReport['links_updated']);
        self::assertSame(1, $secondReport['already_linked']);

        $canonical = OrganizationAsset::query()->sole();
        self::assertSame($canonical->id, $legacy->fresh()->organization_asset_id);
        self::assertSame($canonical->id, $assignment->fresh()->organization_asset_id);
        self::assertSame(
            ['id' => $legacy->id, 'table' => 'machinery_assets'],
            $canonical->metadata['legacy_source'],
        );
        self::assertEquals(['daily_rate' => 7500, 'version' => 'legacy-v3'], $canonical->metadata['rental_terms']);
        self::assertSame(AssetOperationalMode::ShiftOperation, $canonical->operationProfile->operational_mode);
        self::assertTrue($canonical->operationProfile->tracks_meter);
        self::assertTrue($canonical->operationProfile->tracks_fuel);
        self::assertTrue($canonical->operationProfile->tracks_production);
        self::assertTrue($canonical->operationProfile->maintenance_enabled);
        self::assertSame('1000.00', $canonical->operationProfile->operating_cost_per_hour);
        self::assertSame('diesel', $canonical->operationProfile->fuel_type);
        self::assertNull($canonical->operationProfile->fuel_consumption_rate);
        self::assertSame('12.00', $canonical->operationProfile->meter_value);
    }

    public function test_duplicate_legacy_inventory_numbers_are_reported_and_not_auto_resolved(): void
    {
        $context = AdminApiTestContext::create();
        $first = $this->createMachineryAsset((int) $context->organization->id, 'BF-CONFLICT-A', 'INV-CONFLICT');
        $second = $this->createMachineryAsset((int) $context->organization->id, 'BF-CONFLICT-B', 'INV-CONFLICT');

        $exitCode = Artisan::call('assets:backfill', ['--format' => 'json']);
        $report = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertSame(2, $report['conflicts']);
        self::assertCount(2, $report['conflict_records']);
        self::assertSame(0, OrganizationAsset::query()->count());
        self::assertNull($first->fresh()->organization_asset_id);
        self::assertNull($second->fresh()->organization_asset_id);
    }

    public function test_same_project_legacy_overlap_is_normalized_at_the_newer_assignment_boundary(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId]);
        $legacy = $this->createMachineryAsset($organizationId, 'BF-OVERLAP', 'INV-BF-OVERLAP');
        $boundary = now()->subHour()->startOfSecond();
        $assignments = collect([now()->subHours(2)->startOfSecond(), $boundary])
            ->map(fn ($plannedStartAt) => MachineryAssignment::query()->create([
                'organization_id' => $organizationId,
                'asset_id' => $legacy->id,
                'project_id' => $project->id,
                'requested_by_user_id' => $context->user->id,
                'status' => 'active',
                'planned_start_at' => $plannedStartAt,
            ]));

        self::assertSame(0, Artisan::call('assets:backfill', ['--dry-run' => true, '--format' => 'json']));
        $dryRun = $this->jsonOutput();
        self::assertSame(1, $dryRun['would_normalize_assignment_periods']);
        self::assertSame(1, $dryRun['would_reconcile_placements']);
        self::assertNull($assignments[0]->fresh()->planned_end_at);
        self::assertNull($legacy->fresh()->organization_asset_id);

        $exitCode = Artisan::call('assets:backfill', ['--format' => 'json']);
        $report = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame(1, $report['assignment_periods_normalized']);
        self::assertSame($boundary->toDateTimeString(), $assignments[0]->fresh()->planned_end_at?->toDateTimeString());
        self::assertSame($project->id, $legacy->fresh()->current_project_id);
        self::assertSame($project->id, OrganizationAsset::query()->sole()->current_project_id);
        self::assertSame(0, MachineryAssignment::query()->whereNull('organization_asset_id')->count());
        self::assertSame(0, Artisan::call('assets:verify-cutover', ['--format' => 'json']));
    }

    public function test_shared_project_assignment_is_backfilled_without_scope_rewrite(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $sharedProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        DB::table('project_organization')->insert([
            'project_id' => $sharedProject->id,
            'organization_id' => $organizationId,
            'role' => 'contractor',
            'role_new' => 'contractor',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacy = $this->createMachineryAsset($organizationId, 'BF-SHARED-PROJECT', 'INV-BF-SHARED-PROJECT');
        $legacy->update(['current_project_id' => $sharedProject->id]);
        $assignment = MachineryAssignment::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $legacy->id,
            'project_id' => $sharedProject->id,
            'requested_by_user_id' => $context->user->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
        ]);

        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));

        self::assertSame($sharedProject->id, OrganizationAsset::query()->sole()->current_project_id);
        self::assertNotNull($assignment->fresh()->organization_asset_id);
        self::assertSame(0, Artisan::call('assets:verify-cutover', ['--format' => 'json']));
    }

    public function test_equal_start_overlap_remains_a_hard_conflict_without_partial_backfill(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId]);
        $legacy = $this->createMachineryAsset($organizationId, 'BF-AMBIGUOUS', 'INV-BF-AMBIGUOUS');
        $start = now()->subHour()->startOfSecond();
        foreach (range(1, 2) as $_) {
            MachineryAssignment::query()->create([
                'organization_id' => $organizationId,
                'asset_id' => $legacy->id,
                'project_id' => $project->id,
                'requested_by_user_id' => $context->user->id,
                'status' => 'active',
                'planned_start_at' => $start,
            ]);
        }

        self::assertSame(1, Artisan::call('assets:backfill', ['--format' => 'json']));
        $report = $this->jsonOutput();

        self::assertSame('ambiguous_active_assignments', $report['conflict_records'][0]['reason']);
        self::assertSame(0, OrganizationAsset::query()->count());
        self::assertNull($legacy->fresh()->organization_asset_id);
    }

    public function test_all_ownership_types_and_legacy_states_are_preserved_without_name_matching(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $owned = $this->createMachineryAsset((int) $context->organization->id, 'BF-OWNED', 'INV-BF-OWNED');
        $owned->update(['name' => 'Одинаковое название']);
        $leased = $this->createMachineryAsset((int) $context->organization->id, 'BF-LEASED', 'INV-BF-LEASED');
        $leased->update([
            'name' => 'Одинаковое название',
            'ownership_type' => 'leased',
            'status' => 'maintenance',
            'current_project_id' => $project->id,
        ]);
        $subcontractor = $this->createMachineryAsset((int) $context->organization->id, 'BF-SUB', 'INV-BF-SUB');
        $subcontractor->update([
            'name' => 'Одинаковое название',
            'ownership_type' => 'subcontractor',
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));

        $canonical = OrganizationAsset::query()->get()->keyBy(
            static fn (OrganizationAsset $asset): int => (int) $asset->metadata['legacy_source']['id'],
        );
        self::assertSame('owned', $canonical[$owned->id]->ownership_type);
        self::assertSame('leased', $canonical[$leased->id]->ownership_type);
        self::assertSame($project->id, $canonical[$leased->id]->current_project_id);
        self::assertSame(AssetTechnicalStatus::Maintenance, $canonical[$leased->id]->technical_status);
        self::assertSame('subcontractor', $canonical[$subcontractor->id]->ownership_type);
        self::assertSame(AssetLifecycleStatus::Retired, $canonical[$subcontractor->id]->lifecycle_status);
    }

    public function test_existing_mismatched_shadow_link_is_reported_and_never_overwritten(): void
    {
        $context = AdminApiTestContext::create();
        $legacy = $this->createMachineryAsset((int) $context->organization->id, 'BF-MISMATCH', 'INV-BF-MISMATCH');
        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));
        $mapped = OrganizationAsset::query()->sole();
        $wrong = OrganizationAsset::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Другая карточка',
            'inventory_number' => 'INV-WRONG-LINK',
        ]);
        $legacy->update(['organization_asset_id' => $wrong->id]);

        self::assertSame(1, Artisan::call('assets:backfill', ['--format' => 'json']));
        $report = $this->jsonOutput();

        self::assertSame(1, $report['conflicts']);
        self::assertSame('shadow_link_mismatch', $report['conflict_records'][0]['reason']);
        self::assertSame($wrong->id, $legacy->fresh()->organization_asset_id);
        self::assertDatabaseHas('organization_assets', ['id' => $mapped->id]);
    }

    public function test_serialized_warehouse_balance_is_imported_and_explicit_movements_are_linked(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse((int) $context->organization->id);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Шуруповерт Makita',
            'code' => 'MAKITA-DDF485',
            'additional_properties' => ['asset_type' => 'tool'],
        ]);
        $balance = WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 1,
            'reserved_quantity' => 0,
            'average_price' => 15000,
            'serial_number' => 'MAKITA-SERIAL-1',
        ]);
        $movement = WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 1,
            'metadata' => [
                'warehouse_balance_id' => $balance->id,
                'reporting_source_version' => 1,
                'unit_dimension' => 'item',
                'unit_code' => 'pcs',
                'unit_conversion_version' => 'v1',
            ],
            'movement_date' => now(),
        ]);
        DB::table('warehouse_inventory_events')->insert([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'project_id' => null,
            'material_id' => $material->id,
            'source_movement_id' => $movement->id,
            'source_version' => 1,
            'event_type' => 'receipt',
            'on_hand_delta' => 1,
            'reserved_delta' => 0,
            'unit_dimension' => 'item',
            'unit_code' => 'pcs',
            'conversion_version' => 'v1',
            'occurred_at' => $movement->movement_date,
            'source_hash' => str_repeat('0', 64),
            'source_refs' => '{}',
        ]);

        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));

        $canonical = OrganizationAsset::query()->sole();
        self::assertSame($material->id, $canonical->material_id);
        self::assertSame($warehouse->id, $canonical->current_warehouse_id);
        self::assertSame('WHB-'.$balance->id, $canonical->inventory_number);
        self::assertSame('MAKITA-SERIAL-1', $canonical->serial_number);
        self::assertSame(['id' => $balance->id, 'table' => 'warehouse_balances'], $canonical->metadata['legacy_source']);
        self::assertSame($canonical->id, $movement->fresh()->organization_asset_id);

        try {
            DB::transaction(static function () use ($movement): void {
                WarehouseMovement::query()->whereKey($movement->id)->update(['quantity' => 2]);
            });
            self::fail('Linked movement identity must remain immutable.');
        } catch (QueryException) {
            self::assertSame('1.000', $movement->fresh()->quantity);
        }
    }

    public function test_apply_propagates_canonical_id_to_defects_and_maintenance_inspections(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId]);
        $legacy = $this->createMachineryAsset($organizationId, 'BF-LATE-OPS', 'INV-BF-LATE-OPS');
        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));
        $canonicalId = (int) $legacy->fresh()->organization_asset_id;

        $defect = MachineryDefect::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $legacy->id,
            'project_id' => $project->id,
            'defect_code' => 'other',
            'severity' => 'minor',
            'status' => 'open',
            'description' => 'Исторический дефект',
            'reported_at' => now(),
        ]);
        $order = MachineryMaintenanceOrder::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $legacy->id,
            'project_id' => $project->id,
            'requested_by_user_id' => $context->user->id,
            'order_number' => 'BF-LATE-ORDER',
            'title' => 'Историческое обслуживание',
            'maintenance_type' => 'repair',
            'priority' => 'normal',
            'status' => 'completed',
        ]);
        $inspection = MaintenanceInspection::query()->create([
            'organization_id' => $organizationId,
            'maintenance_order_id' => $order->id,
            'asset_id' => $legacy->id,
            'inspected_by_user_id' => $context->user->id,
            'result' => 'passed',
            'inspected_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));

        self::assertSame($canonicalId, $defect->fresh()->organization_asset_id);
        self::assertSame($canonicalId, $order->fresh()->organization_asset_id);
        self::assertSame($canonicalId, $inspection->fresh()->organization_asset_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createMachineryAsset(int $organizationId, string $assetCode, ?string $inventoryNumber): MachineryAsset
    {
        return MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'asset_code' => $assetCode,
            'name' => 'Техника '.$assetCode,
            'inventory_number' => $inventoryNumber,
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 1000,
            'fuel_type' => 'diesel',
            'meter_hours' => 12,
        ]);
    }

    private function createWarehouse(int $organizationId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Главный склад',
            'code' => 'BF-MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
    }
}

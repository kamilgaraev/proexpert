<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

#[Group('postgresql')]
final class ProjectMaterialDeliveryConcurrencyTest extends TestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Concurrent delivery tests require Linux PHP with pcntl and posix.');
        }
        RefreshDatabaseState::$migrated = false;
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_two_processes_cannot_overship_one_project_delivery(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->users()->attach($actor->id, ['is_active' => true]);
        $now = now('UTC');
        $materialId = DB::table('materials')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Race material',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = DB::table('organization_warehouses')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Race source',
            'code' => 'RACE-'.$organization->id,
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('warehouse_balances')->insert([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'unit_price' => '100.00',
            'created_at' => $now,
        ]);
        $allocation = WarehouseProjectAllocation::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'project_id' => $project->id,
            'allocated_quantity' => '10.000',
            'allocated_by_user_id' => $actor->id,
            'allocated_at' => $now,
        ]);
        $delivery = ProjectMaterialDelivery::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'material_id' => $materialId,
            'warehouse_id' => $warehouseId,
            'warehouse_project_allocation_id' => $allocation->id,
            'source_type' => 'warehouse',
            'status' => 'reserved',
            'requested_quantity' => '10.000',
            'reserved_quantity' => '10.000',
            'shipped_quantity' => '0.000',
            'accepted_quantity' => '0.000',
        ]);
        DB::disconnect();
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-supply-overship-'.bin2hex(random_bytes(8)),
        );
        $children = [];
        try {
            foreach ([0, 1] as $index) {
                $children[] = $harness->spawn($index, static function () use ($delivery, $actor): array {
                    try {
                        app(ProjectMaterialDeliveryService::class)->ship(
                            ProjectMaterialDelivery::query()->findOrFail($delivery->id),
                            User::query()->findOrFail($actor->id),
                            ['quantity' => 6],
                        );

                        return ['state' => 'applied'];
                    } catch (\DomainException) {
                        return ['state' => 'conflict'];
                    }
                });
            }
            $harness->release(0);
            $harness->release(1);
            $harness->waitForChildren($children);
            $states = [
                (string) $harness->result(0)['state'],
                (string) $harness->result(1)['state'],
            ];
            sort($states);
            self::assertSame(['applied', 'conflict'], $states);
            self::assertSame(
                '6.000',
                (string) ProjectMaterialDelivery::query()->findOrFail($delivery->id)->shipped_quantity,
            );
            self::assertSame('4.000', (string) DB::table('warehouse_balances')
                ->where('warehouse_id', $warehouseId)->where('material_id', $materialId)->value('available_quantity'));
            self::assertSame(2, DB::table('warehouse_movements')
                ->where('project_material_delivery_id', $delivery->id)->count());
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }
}

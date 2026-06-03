<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectMaterialDeliveryMovementTest extends TestCase
{
    public function test_project_delivery_can_store_project_warehouse_and_movement_links(): void
    {
        $this->assertTrue(Schema::hasColumn('organization_warehouses', 'project_id'));
        $this->assertTrue(Schema::hasColumn('organization_warehouses', 'responsible_user_id'));
        $this->assertTrue(Schema::hasColumn('project_material_deliveries', 'project_warehouse_id'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'related_user_id'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'operation_category'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'project_material_delivery_id'));
        $this->assertTrue(Schema::hasColumn('journal_materials', 'warehouse_movement_id'));
        $this->assertTrue(Schema::hasColumn('journal_materials', 'custody_warehouse_id'));
    }

    public function test_custody_warehouse_can_be_linked_to_project_and_responsible_user(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $responsibleUser = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $responsibleUser->id,
            'name' => 'Ответственный: ' . $responsibleUser->name,
            'code' => 'CUST-' . $responsibleUser->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_active' => true,
            'is_main' => false,
        ]);

        $this->assertSame(OrganizationWarehouse::TYPE_CUSTODY, $warehouse->warehouse_type);
        $this->assertSame($project->id, $warehouse->project?->id);
        $this->assertSame($responsibleUser->id, $warehouse->responsibleUser?->id);
    }
}

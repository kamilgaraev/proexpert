<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantCatalogLookupReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationPurchaseOrdersReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOwnProjectAccessReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectDailyStaffReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectDeliveriesReader;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\WorkType;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDailyManagementCoverageTest extends TestCase
{
    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake();
        config(['cache.default' => 'array']);

        return Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
    }

    private function readResult(callable $read): mixed
    {
        return $read();
    }

    public function test_own_access_counts_modes_active_assignments_and_revocation(): void
    {
        $f = $this->fixture();
        $org = (int) $f->organization->id;
        $actor = $f->owner;
        $one = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'is_archived' => false]));
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'is_archived' => false]));
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'is_archived' => true]));
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $f->foreignOrganization->id, 'name' => 'FOREIGN_PRIVATE']));
        $actor->assignedProjects()->attach($one->id, ['role' => 'member', 'is_active' => true]);
        $reader = app(AssistantOwnProjectAccessReader::class);
        $args = ['scope' => 'organization'];
        $result = $this->readResult(fn () => $reader->read($actor, $org, $args));
        self::assertSame('all_projects', $result['data']['mode']);
        self::assertSame(2, $result['data']['total']);
        self::assertSame(1, $result['data']['assigned']);
        self::assertSame(1, $result['data']['general']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        $actor->organizations()->updateExistingPivot($org, ['project_access_mode' => 'assigned_projects']);
        self::assertFalse($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        $limited = $reader->read($actor, $org, $args);
        self::assertSame(1, $limited['data']['total']);
        self::assertSame(0, $limited['data']['general']);
        $actor->organizations()->updateExistingPivot($org, ['is_active' => false]);
        self::assertFalse($reader->matchesReference($actor, $org, $limited['source_refs'][0]));
    }

    public function test_orders_and_deliveries_count_before_window_and_exclude_final_other_tenant_and_project(): void
    {
        $f = $this->fixture();
        $org = (int) $f->organization->id;
        $actor = $f->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $supplier = Supplier::withoutEvents(fn () => Supplier::query()->create(['organization_id' => $org, 'name' => 'Поставщик']));
        $material = Material::withoutEvents(fn () => Material::query()->create(['organization_id' => $org, 'name' => 'Цемент']));
        Model::withoutEvents(function () use ($org, $supplier, $material, $project, $other, $f): void {
            foreach (range(1, 23) as $i) {
                PurchaseOrder::query()->create(['organization_id' => $org, 'supplier_id' => $supplier->id, 'order_number' => 'DAILY-'.$org.'-'.$i,
                    'order_date' => today(), 'status' => 'in_delivery', 'delivery_date' => $i === 23 ? null : today()->subDay(), 'notes' => 'PRIVATE_NOTES', 'total_amount' => 987654]);
                ProjectMaterialDelivery::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'material_id' => $material->id,
                    'requested_quantity' => 1, 'source_type' => $i % 2 ? 'purchase' : 'warehouse', 'status' => 'processing',
                    'planned_delivery_date' => $i === 23 ? null : today()->subDay(), 'notes' => 'PRIVATE_NOTES']);
            }
            PurchaseOrder::query()->create(['organization_id' => $org, 'supplier_id' => $supplier->id, 'order_number' => 'FINAL-'.$org, 'order_date' => today(), 'status' => 'delivered']);
            PurchaseOrder::query()->create(['organization_id' => $f->foreignOrganization->id, 'supplier_id' => $supplier->id, 'order_number' => 'FOREIGN_PRIVATE-'.$org, 'order_date' => today(), 'status' => 'sent']);
            foreach (['accepted', 'cancelled'] as $status) {
                ProjectMaterialDelivery::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'material_id' => $material->id, 'requested_quantity' => 1, 'source_type' => 'manual', 'status' => $status]);
            }
            ProjectMaterialDelivery::query()->create(['organization_id' => $org, 'project_id' => $other->id, 'material_id' => $material->id, 'requested_quantity' => 1, 'source_type' => 'manual', 'status' => 'processing']);
        });
        $orders = app(AssistantOrganizationPurchaseOrdersReader::class);
        $o = $this->readResult(fn () => $orders->read($actor, $org, ['scope' => 'organization']));
        self::assertSame(24, $o['data']['total']);
        self::assertSame(23, $o['data']['open']);
        self::assertSame(22, $o['data']['overdue']);
        self::assertCount(20, $o['data']['examples']);
        self::assertStringNotContainsString('PRIVATE', json_encode($o));
        self::assertStringNotContainsString('987654', json_encode($o));
        $deliveries = app(AssistantProjectDeliveriesReader::class);
        $d = $this->readResult(fn () => $deliveries->read($actor, $org, ['project_id' => (int) $project->id]));
        self::assertSame(23, $d['data']['total']);
        self::assertSame(22, $d['data']['overdue']);
        self::assertSame(1, $d['data']['undated']);
        self::assertCount(20, $d['data']['examples']);
        self::assertSame('Цемент', $d['data']['examples'][0]['material']);
        self::assertStringNotContainsString('PRIVATE', json_encode($d));
        $material->updateQuietly(['name' => 'Новое название']);
        self::assertFalse($deliveries->matchesReference($actor, $org, $d['source_refs'][0]));
        PurchaseOrder::query()->where('organization_id', $org)->where('status', 'in_delivery')->update(['status' => 'delivered']);
        self::assertFalse($orders->matchesReference($actor, $org, $o['source_refs'][0]));
        $current = $orders->read($actor, $org, ['scope' => 'organization']);
        foreach (['basic-warehouse', 'procurement'] as $slug) {
            $module = \App\Models\Module::query()->where('slug', $slug)->firstOrFail();
            $active = $module->is_active;
            try {
                $module->updateQuietly(['is_active' => false]);
                self::assertFalse(app(\App\Services\Entitlements\OrganizationEntitlementService::class)->hasModuleAccess($org, $slug));
                self::assertTrue((bool) $f->ownerAssignment->fresh()->is_active);
                self::assertFalse($orders->matchesReference($actor, $org, $current['source_refs'][0]));
            } finally { $module->updateQuietly(['is_active' => $active]); }
        }
    }

    public function test_catalog_reads_both_types_units_full_counts_and_current_permissions(): void
    {
        $f = $this->fixture();
        $org = (int) $f->organization->id;
        $unit = MeasurementUnit::withoutEvents(fn () => MeasurementUnit::query()->create(['organization_id' => $org, 'name' => 'Кубический метр', 'short_name' => 'м3']));
        Model::withoutEvents(function () use ($org, $unit, $f): void {
            foreach (range(1, 23) as $i) {
                Material::query()->create(['organization_id' => $org, 'name' => 'Цемент '.$i, 'code' => 'C'.$i, 'measurement_unit_id' => $unit->id, 'default_price' => 987654]);
            }
            WorkType::query()->create(['organization_id' => $org, 'name' => 'Бетонирование фундаментов', 'measurement_unit_id' => $unit->id]);
            WorkType::query()->create(['organization_id' => $org, 'name' => 'Бетоинирование', 'measurement_unit_id' => $unit->id]);
            Material::query()->create(['organization_id' => $f->foreignOrganization->id, 'name' => 'Цемент FOREIGN_PRIVATE']);
        });
        $reader = app(AssistantCatalogLookupReader::class);
        $args = ['material_query' => 'цемент', 'work_type_query' => 'бетонированию'];
        $result = $this->readResult(fn () => $reader->read($f->owner, $org, $args));
        self::assertSame(23, $result['data']['material']['total']);
        self::assertCount(20, $result['data']['material']['rows']);
        self::assertSame(2, $result['data']['work_type']['total']);
        self::assertSame('Кубический метр (м3)', $result['data']['work_type']['rows'][0]['unit']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        self::assertStringNotContainsString('987654', json_encode($result));
        Material::withoutEvents(fn () => Material::query()->create(['organization_id' => $org, 'name' => 'Цемент без единицы']));
        $member = $f->addMember(['ai-assistant' => ['ai_assistant.chat'], 'catalog-management' => ['materials.view']]);
        $limited = $reader->read($member, $org, $args);
        self::assertFalse($limited['data']['work_type']['available']);
        self::assertArrayNotHasKey('total', $limited['data']['work_type']);
        self::assertSame(1, $limited['data']['material']['total']);
        self::assertNull($limited['data']['material']['rows'][0]['unit']);
        $unit->updateQuietly(['short_name' => 'куб.м']);
        self::assertFalse($reader->matchesReference($f->owner, $org, $result['source_refs'][0]));
    }

    public function test_calendar_uses_approved_native_absences_trips_and_distinct_current_employees(): void
    {
        $f = $this->fixture();
        $org = (int) $f->organization->id;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $ts = ['created_at' => now(), 'updated_at' => now()];
        $dept = DB::table('workforce_departments')->insertGetId(['organization_id' => $org, 'code' => 'DAILY', 'name' => 'Монтаж'] + $ts);
        $position = DB::table('workforce_positions')->insertGetId(['organization_id' => $org, 'code' => 'DAILY', 'name' => 'Монтажник'] + $ts);
        $unit = DB::table('workforce_staff_units')->insertGetId(['organization_id' => $org, 'department_id' => $dept, 'position_id' => $position, 'code' => 'DAILY', 'valid_from' => today()->subMonth()] + $ts);
        $type = DB::table('workforce_absence_types')->insertGetId(['organization_id' => $org, 'code' => 'sick_daily', 'name' => 'Больничный'] + $ts);
        foreach (range(1, 3) as $i) {
            $employee = DB::table('workforce_employees')->insertGetId(['organization_id' => $org, 'personnel_number' => 'DAY-'.$i, 'last_name' => 'Иванов', 'first_name' => 'Иван'.$i, 'hire_date' => today()->subYear(), 'email' => 'PRIVATE_EMAIL'] + $ts);
            $assignment = ['organization_id' => $org, 'employee_id' => $employee, 'staff_unit_id' => $unit, 'department_id' => $dept, 'position_id' => $position, 'project_id' => $project->id, 'status' => 'active', 'valid_from' => today()->subMonth()] + $ts;
            DB::table('workforce_employee_assignments')->insert($assignment);
            if ($i === 1) {
                DB::table('workforce_employee_assignments')->insert($assignment);
            }
            if ($i !== 2) {
                DB::table('workforce_absences')->insert(['organization_id' => $org, 'employee_id' => $employee, 'absence_type_id' => $type, 'start_date' => today(), 'end_date' => today(), 'status' => $i === 1 ? 'approved' : 'draft'] + $ts);
            } else {
                DB::table('workforce_business_trips')->insert(['organization_id' => $org, 'employee_id' => $employee, 'start_date' => today(), 'end_date' => today(), 'destination' => 'PRIVATE_DESTINATION', 'status' => 'approved'] + $ts);
            }
        }
        $reader = app(AssistantProjectDailyStaffReader::class);
        $args = ['project_id' => (int) $project->id];
        $result = $this->readResult(fn () => $reader->read($f->owner, $org, $args));
        self::assertSame(3, $result['data']['employees']);
        self::assertSame(1, $result['data']['absent']);
        self::assertSame(1, $result['data']['trips']);
        self::assertCount(2, $result['data']['examples']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        self::assertTrue($reader->matchesReference($f->owner, $org, $result['source_refs'][0]));
        try {
            app(WorkforceProService::class)->scheduleCalendar($org, today()->toDateString(), today()->toDateString(), (int) $project->id, 1);
            self::fail('Expected bound');
        } catch (\DomainException $e) {
            self::assertSame('calendar_read_limit', $e->getMessage());
        }
        DB::table('workforce_absences')->where('organization_id', $org)->where('status', 'approved')->update(['status' => 'cancelled']);
        self::assertFalse($reader->matchesReference($f->owner, $org, $result['source_refs'][0]));
    }
}

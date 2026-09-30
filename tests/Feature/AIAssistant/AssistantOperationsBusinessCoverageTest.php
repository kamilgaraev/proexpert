<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OperationsSafetyMedicalRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OperationsQualityRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OperationsWarehouseRagSource;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyMedicalExam;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Log\NullLogger;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class AssistantOperationsBusinessCoverageTest extends TestCase
{
    public function test_medical_employee_private_project_scope_precedes_limit_and_observes_current_assignment_and_permission(): void
    {
        $fixture = $this->fixture();
        $member = $fixture->addMember(['safety-management' => ['safety-management.view'], 'project-management' => ['projects.view']]);
        $hidden = $this->project($fixture->organization);
        $visible = $this->project($fixture->organization);
        $member->assignedProjects()->attach($visible->id, ['role' => 'member', 'is_active' => true]);
        [$hiddenEmployee, $hiddenAssignment] = $this->employee($fixture->organization, $hidden, 'hidden');
        [$allowedEmployee] = $this->employee($fixture->organization, $visible, 'allowed');
        $hiddenExams = [];
        for ($index = 0; $index < 25; $index++) { $hiddenExams[] = $this->medical($fixture->organization->id, $hiddenEmployee->id); }
        $allowed = $this->medical($fixture->organization->id, $allowedEmployee->id);
        [$foreignEmployee] = $this->employee($fixture->foreignOrganization, $this->project($fixture->foreignOrganization), 'foreign');
        $wrongTenantParent = $this->medical($fixture->organization->id, $foreignEmployee->id);
        $policy = app(AssistantDataAccessPolicy::class);
        $query = $policy->entityQuery($member, $fixture->organization->id, 'safety_medical_exam');
        self::assertNotNull($query);
        self::assertSame([$allowed->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'safety_medical_exam', $wrongTenantParent->id));
        self::assertFalse($policy->canReadSource($member, $fixture->organization->id, $this->medicalReference($hiddenExams[0])));
        self::assertTrue($policy->canReadSource($member, $fixture->organization->id, $this->medicalReference($allowed)));
        DB::table('workforce_employee_assignments')->where('id', $hiddenAssignment)->update(['valid_to' => now()->subDay()->toDateString()]);
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'safety_medical_exam', $hiddenExams[0]->id));
        $role = $this->role($member);
        $permissions = $role->module_permissions;
        $permissions['safety-management'] = [];
        $role->update(['module_permissions' => $permissions]);
        self::assertFalse($policy->canReadSource($member, $fixture->organization->id, $this->medicalReference($allowed)));
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'safety_medical_exam', $allowed->id));
        $chunks = iterator_to_array((new OperationsSafetyMedicalRagSource)->collectEntity($fixture->organization->id, 'safety_medical_exam', $allowed->id));
        self::assertCount(1, $chunks);
        self::assertStringNotContainsString('private-medical-notes', $chunks[0]->content);
        self::assertArrayNotHasKey('restrictions', $chunks[0]->metadata);
    }

    public function test_warehouse_line_inherits_current_project_and_parent_rebind_or_membership_revocation_denies_old_source(): void
    {
        $fixture = $this->fixture();
        $member = $this->warehouseMember($fixture);
        $hidden = $this->project($fixture->organization);
        $visible = $this->project($fixture->organization);
        $member->assignedProjects()->attach($visible->id, ['role' => 'member', 'is_active' => true]);
        $hiddenAct = $this->inventory($fixture, $hidden, 'hidden');
        $allowedAct = $this->inventory($fixture, $visible, 'allowed');
        $material = $this->material($fixture->organization);
        $hiddenItem = $this->item($hiddenAct->id, $material->id, 'hidden');
        $allowedItem = $this->item($allowedAct->id, $material->id, 'allowed');
        $policy = app(AssistantDataAccessPolicy::class);
        $query = $policy->entityQuery($member, $fixture->organization->id, 'inventory_act_item');
        self::assertNotNull($query);
        self::assertSame([$allowedItem->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'inventory_act', $hiddenAct->id));
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'inventory_act', $allowedAct->id));
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'inventory_act_item', $hiddenItem->id));
        $reference = ['source_type' => 'operations_warehouse', 'entity_type' => 'inventory_act_item', 'entity_id' => $allowedItem->id,
            'organization_id' => $fixture->organization->id, 'project_id' => $visible->id];
        self::assertTrue($policy->canReadSource($member, $fixture->organization->id, $reference));
        DB::table('inventory_act_items')->where('id', $allowedItem->id)->update(['inventory_act_id' => $hiddenAct->id]);
        self::assertFalse($policy->canReadSource($member, $fixture->organization->id, $reference));
        DB::table('inventory_act_items')->where('id', $allowedItem->id)->update(['inventory_act_id' => $allowedAct->id]);
        self::assertTrue($policy->canReadSource($member, $fixture->organization->id, $reference));
        $foreignFixture = $this->inventory($fixture, $this->project($fixture->foreignOrganization), 'foreign', $fixture->foreignOrganization);
        DB::table('inventory_act_items')->where('id', $allowedItem->id)->update(['inventory_act_id' => $foreignFixture->id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'inventory_act_item', $allowedItem->id));
        self::assertSame([], iterator_to_array((new OperationsWarehouseRagSource)->collectEntity($fixture->organization->id, 'inventory_act_item', $allowedItem->id)));
        DB::table('inventory_act_items')->where('id', $allowedItem->id)->update(['inventory_act_id' => $allowedAct->id]);
        $member->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => false]);
        self::assertFalse($policy->canReadSource($member, $fixture->organization->id, $reference));
    }

    public function test_storage_cell_requires_current_warehouse_and_zone_warehouse_binding_before_limit(): void
    {
        $fixture = $this->fixture();
        $member = $fixture->addMember(['basic-warehouse' => ['warehouse.view', 'warehouse.advanced.zones', 'warehouse.manage_stock', 'warehouse.inventory'], 'catalog-management' => ['materials.view', 'measurement_units.view'], 'project-management' => ['projects.view']]);
        $visible = $this->project($fixture->organization);
        $hidden = $this->project($fixture->organization);
        $member->assignedProjects()->attach($visible->id, ['role' => 'member', 'is_active' => true]);
        $visibleAct = $this->inventory($fixture, $visible, 'visible-cell');
        $hiddenAct = $this->inventory($fixture, $hidden, 'hidden-cell');
        $foreignAct = $this->inventory($fixture, $this->project($fixture->foreignOrganization), 'foreign-cell', $fixture->foreignOrganization);
        $createZone = static fn (int $warehouseId, string $code) => Model::withoutEvents(fn () => \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone::query()->create(['warehouse_id' => $warehouseId, 'name' => 'Зона', 'code' => $code, 'zone_type' => 'storage', 'is_active' => true]));
        $zone = $createZone($visibleAct->warehouse_id, 'visible');
        $hiddenZone = $createZone($hiddenAct->warehouse_id, 'hidden');
        $foreignZone = $createZone($foreignAct->warehouse_id, 'foreign');
        $createCell = static fn (int $warehouseId, int $zoneId, string $code) => Model::withoutEvents(fn () => \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell::query()->create(['organization_id' => $fixture->organization->id, 'warehouse_id' => $warehouseId, 'zone_id' => $zoneId, 'name' => 'Ячейка', 'code' => $code, 'cell_type' => 'storage', 'status' => 'available', 'is_active' => true]));
        $hiddenCell = $createCell($hiddenAct->warehouse_id, $hiddenZone->id, 'hidden');
        $cell = $createCell($visibleAct->warehouse_id, $zone->id, 'visible');
        $policy = app(AssistantDataAccessPolicy::class);
        $query = $policy->entityQuery($member, $fixture->organization->id, 'warehouse_storage_cell');
        self::assertNotNull($query);
        self::assertSame([$cell->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'warehouse_storage_cell', $hiddenCell->id));
        DB::table('warehouse_storage_cells')->where('id', $cell->id)->update(['zone_id' => $foreignZone->id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'warehouse_storage_cell', $cell->id));
        DB::table('warehouse_storage_cells')->where('id', $cell->id)->update(['zone_id' => $hiddenZone->id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'warehouse_storage_cell', $cell->id));
        DB::table('warehouse_storage_cells')->where('id', $cell->id)->update(['zone_id' => $zone->id]);
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'warehouse_storage_cell', $cell->id));
        $item = $this->item($visibleAct->id, $this->material($fixture->organization)->id, 'optional-cell');
        self::assertNull($item->cell_id);
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'inventory_act_item', $item->id));
        self::assertCount(1, [...(new OperationsWarehouseRagSource)->collectEntity($fixture->organization->id, 'inventory_act_item', $item->id)]);
        DB::table('inventory_act_items')->where('id', $item->id)->update(['cell_id' => $hiddenCell->id]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'inventory_act_item', $item->id));
        $foreignCell = Model::withoutEvents(fn () => \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell::query()->create([
            'organization_id' => $fixture->foreignOrganization->id, 'warehouse_id' => $foreignAct->warehouse_id,
            'zone_id' => $foreignZone->id, 'name' => 'Чужая ячейка', 'code' => 'foreign', 'cell_type' => 'storage', 'status' => 'available', 'is_active' => true]));
        DB::table('inventory_act_items')->where('id', $item->id)->update(['cell_id' => $foreignCell->id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'inventory_act_item', $item->id));
        self::assertSame([], [...(new OperationsWarehouseRagSource)->collectEntity($fixture->organization->id, 'inventory_act_item', $item->id)]);
        DB::table('inventory_act_items')->where('id', $item->id)->update(['cell_id' => $cell->id]);
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'inventory_act_item', $item->id));

        $identifier = Model::withoutEvents(fn () => \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier::query()->create(
            ['organization_id' => $fixture->organization->id, 'warehouse_id' => $visibleAct->warehouse_id, 'identifier_type' => 'internal',
                'code' => 'visible-cell-id', 'entity_type' => 'cell', 'entity_id' => $cell->id, 'status' => 'active']));
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'warehouse_identifier', $identifier->id));
        DB::table('warehouse_identifiers')->where('id', $identifier->id)->update(['entity_id' => $hiddenCell->id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'warehouse_identifier', $identifier->id));
        self::assertSame([], iterator_to_array((new OperationsWarehouseRagSource)->collectEntity($fixture->organization->id, 'warehouse_identifier', $identifier->id)));
        DB::table('warehouse_identifiers')->where('id', $identifier->id)->update(['entity_id' => $cell->id]);
        $member->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'warehouse_storage_cell', $cell->id));
    }

    public function test_every_warehouse_line_is_collected_across_batches_and_real_index_job_reuses_updates_and_removes_deletions(): void
    {
        $fixture = $this->fixture();
        $project = $this->project($fixture->organization);
        $act = $this->inventory($fixture, $project, 'batch');
        $material = $this->material($fixture->organization);
        $items = [];
        for ($index = 0; $index < 63; $index++) { $items[] = $this->item($act->id, $material->id, 'batch-'.$index); }
        $source = new OperationsWarehouseRagSource;
        $rows = array_values(array_filter(iterator_to_array($source->collectForOrganization($fixture->organization->id)), static fn ($row): bool => $row->entityType === 'inventory_act_item'));
        self::assertCount(63, $rows);
        self::assertSame(array_map(static fn (InventoryActItem $row): int => $row->id, $items), array_column($rows, 'entityId'));
        foreach ($rows as $row) {
            self::assertSame($project->id, $row->projectId);
            self::assertArrayNotHasKey('unit_price', $row->metadata);
            self::assertStringNotContainsString('987654.32', $row->content);
        }
        $jobs = new class { public array $items = []; };
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(static function (IndexRagSourceJob $job) use ($jobs): null { $jobs->items[] = $job; return null; });
        $embedding = $this->createMock(RagEmbeddingProviderInterface::class);
        $embedding->method('embed')->willReturn(RagTestEmbedding::fromLeadingValues([1.0]));
        $embedding->method('provider')->willReturn('test');
        $embedding->method('model')->willReturn('operations-lifecycle');
        $embedding->method('dimensions')->willReturn(RagTestEmbedding::DIMENSIONS);
        $indexer = new RagIndexer($embedding, new RagSourceRegistry([$source]));
        $coordinator = new RagIndexingCoordinator($indexer, new RagJobDispatcher($bus, new NullLogger));
        $item = $items[62];
        DB::beginTransaction();
        $coordinator->queueEntity($fixture->organization->id, $project->id, 'operations_warehouse', 'inventory_act_item', $item->id);
        self::assertSame([], $jobs->items);
        DB::commit();
        self::assertCount(1, $jobs->items);
        $jobs->items[0]->handle($indexer, $coordinator);
        $indexed = RagSource::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'inventory_act_item')->where('entity_id', (string) $item->id)->firstOrFail();
        $sourceId = $indexed->id;
        $checksum = $indexed->checksum;
        self::assertGreaterThan(0, $indexed->chunks()->count());
        DB::beginTransaction();
        DB::table('inventory_act_items')->where('id', $item->id)->update(['actual_quantity' => '3.125', 'updated_at' => now()->addMinute()]);
        $coordinator->queueEntity($fixture->organization->id, $project->id, 'operations_warehouse', 'inventory_act_item', $item->id);
        self::assertCount(1, $jobs->items);
        DB::commit();
        self::assertCount(2, $jobs->items);
        $jobs->items[1]->handle($indexer, $coordinator);
        self::assertSame($sourceId, $indexed->fresh()->id);
        self::assertNotSame($checksum, $indexed->fresh()->checksum);
        DB::beginTransaction();
        DB::table('inventory_act_items')->where('id', $item->id)->delete();
        $coordinator->queueEntity($fixture->organization->id, $project->id, 'operations_warehouse', 'inventory_act_item', $item->id);
        DB::commit();
        self::assertCount(3, $jobs->items);
        $jobs->items[2]->handle($indexer, $coordinator);
        self::assertFalse(RagSource::query()->whereKey($sourceId)->exists());
        self::assertSame(0, DB::table('ai_rag_chunks')->where('source_id', $sourceId)->count());
        self::assertCount(62, array_values(array_filter(iterator_to_array($source->collectForOrganization($fixture->organization->id)), static fn ($row): bool => $row->entityType === 'inventory_act_item')));
    }

    public function test_price_is_live_permission_gated_and_its_structured_receipt_is_rejected_after_finance_revocation(): void
    {
        $fixture = $this->fixture();
        $member = $this->warehouseMember($fixture, true);
        $project = $this->project($fixture->organization);
        $member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $act = $this->inventory($fixture, $project, 'money');
        $item = $this->item($act->id, $this->material($fixture->organization)->id, 'money');
        $reader = app(AssistantDomainReadService::class);
        $arguments = ['domain' => 'operations_warehouse', 'entity_type' => 'inventory_act_item', 'id' => $item->id, 'fields' => ['id', 'actual_quantity', 'unit_price']];
        $result = $reader->execute('read', $arguments, $member, $fixture->organization->id);
        self::assertSame('987654.32', $result['financial_evidence']['rows'][0]['fields']['unit_price']);
        self::assertSame('2.125', $result['financial_evidence']['rows'][0]['fields']['actual_quantity']);
        self::assertContains('finance.view', $result['source_refs'][0]['required_permissions']);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadReference($member, $fixture->organization->id, $result['source_refs'][0]));
        $role = $this->role($member);
        $permissions = $role->module_permissions;
        $permissions['payments'] = [];
        $role->update(['module_permissions' => $permissions]);
        self::assertFalse($policy->canReadReference($member, $fixture->organization->id, $result['source_refs'][0]));
        $withoutMoney = $reader->execute('read', $arguments, $member, $fixture->organization->id);
        self::assertArrayNotHasKey('unit_price', $withoutMoney['results'][0]['fields']);
        self::assertNotContains('finance.view', $withoutMoney['source_refs'][0]['required_permissions']);
        self::assertTrue($policy->canReadReference($member, $fixture->organization->id, $withoutMoney['source_refs'][0]));
    }

    public function test_quality_event_and_gap_uuid_keys_are_used_for_real_sources_current_scope_and_search(): void
    {
        $fixture = $this->fixture();
        $member = $fixture->addMember(['quality-control' => ['quality-control.view'], 'project-management' => ['projects.view']]);
        $visible = $this->project($fixture->organization);
        $hidden = $this->project($fixture->organization);
        $member->assignedProjects()->attach($visible->id, ['role' => 'member', 'is_active' => true]);
        $source = new OperationsQualityRagSource;
        $embedding = $this->createMock(RagEmbeddingProviderInterface::class);
        $embedding->method('embed')->willReturn(RagTestEmbedding::fromLeadingValues([1.0]));
        $embedding->method('provider')->willReturn('test');
        $embedding->method('model')->willReturn('operations-uuid');
        $embedding->method('dimensions')->willReturn(RagTestEmbedding::DIMENSIONS);
        $indexer = new RagIndexer($embedding, new RagSourceRegistry([$source]));
        $ids = [];
        foreach ([$hidden, $visible] as $project) {
            [$eventId, $gapId] = Model::withoutEvents(function () use ($fixture, $project): array {
                $service = app(\App\BusinessModules\Features\QualityControl\Services\QualityDefectService::class);
                $created = $service->create($fixture->organization->id, $fixture->owner->id, [
                    'project_id' => $project->id, 'title' => 'Дефект монтажа', 'severity' => 'major', 'inspection_required' => true]);
                $eventId = DB::table('quality_defect_flow_events')->where('quality_defect_id', $created->id)->value('event_id');
                $legacy = \App\BusinessModules\Features\QualityControl\Models\QualityDefect::query()->create([
                    'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                    'defect_number' => 'LEGACY-'.$project->id, 'title' => 'Дефект до внедрения журнала', 'severity' => 'major', 'status' => 'open']);
                $service->assign($legacy, $fixture->owner->id, $fixture->owner->id);
                $gapId = DB::table('quality_defect_flow_gaps')->where('quality_defect_id', $legacy->id)->value('gap_id');
                self::assertIsString($eventId);
                self::assertIsString($gapId);
                return [$eventId, $gapId];
            });
            foreach (['quality_defect_flow_event' => $eventId, 'quality_defect_flow_gap' => $gapId] as $type => $id) {
                $chunks = iterator_to_array($source->collectEntity($fixture->organization->id, $type, $id));
                self::assertCount(1, $chunks);
                self::assertSame($id, $chunks[0]->entityId);
                $indexer->indexEntity($fixture->organization->id, 'operations_quality', $type, $id);
            }
            $ids[] = [$eventId, $gapId];
        }
        $policy = app(AssistantDataAccessPolicy::class);
        $allowed = $policy->applyToSources(RagSource::query(), $member, $fixture->organization->id)->where('source_type', 'operations_quality')->pluck('entity_id')->all();
        sort($allowed);
        $expected = $ids[1];
        sort($expected);
        self::assertSame($expected, $allowed);
        foreach (['quality_defect_flow_event' => ['event_id', $ids[1][0]], 'quality_defect_flow_gap' => ['gap_id', $ids[1][1]]] as $type => [$key, $id]) {
            $result = app(AssistantDomainReadService::class)->execute('search', ['domain' => 'operations_quality', 'entity_type' => $type,
                'query' => '', 'project_id' => null, 'limit' => 1, 'fields' => [$key]], $member, $fixture->organization->id);
            self::assertSame($id, $result['results'][0]['id']);
            self::assertSame($id, $result['source_refs'][0]['entity_id']);
        }
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake();
        return Model::withoutEvents(fn (): AssistantRealAuthorizationFixture => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
    }

    private function project(Organization $organization): Project
    {
        return Project::withoutEvents(fn (): Project => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
    }

    private function warehouseMember(AssistantRealAuthorizationFixture $fixture, bool $finance = false): User
    {
        return $fixture->addMember(['basic-warehouse' => ['warehouse.view', 'warehouse.inventory'],
            'catalog-management' => ['materials.view', 'measurement_units.view'], 'project-management' => ['projects.view'],
            'payments' => $finance ? ['finance.view'] : []]);
    }

    private function role(User $actor): OrganizationCustomRole
    {
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        return OrganizationCustomRole::query()->where('slug', $assignment->role_slug)->firstOrFail();
    }

    private function employee(Organization $organization, Project $project, string $code): array
    {
        $employee = WorkforceEmployee::withoutEvents(fn (): WorkforceEmployee => WorkforceEmployee::query()->create(['organization_id' => $organization->id,
            'personnel_number' => $code, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'hire_date' => now()->subYear()->toDateString(), 'employment_status' => 'active']));
        $department = DB::table('workforce_departments')->insertGetId(['organization_id' => $organization->id, 'code' => $code, 'name' => 'Участок', 'created_at' => now(), 'updated_at' => now()]);
        $position = DB::table('workforce_positions')->insertGetId(['organization_id' => $organization->id, 'code' => $code, 'name' => 'Монтажник', 'created_at' => now(), 'updated_at' => now()]);
        $unit = DB::table('workforce_staff_units')->insertGetId(['organization_id' => $organization->id, 'department_id' => $department,
            'position_id' => $position, 'code' => $code, 'valid_from' => now()->subYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $assignment = DB::table('workforce_employee_assignments')->insertGetId(['organization_id' => $organization->id, 'employee_id' => $employee->id,
            'staff_unit_id' => $unit, 'department_id' => $department, 'position_id' => $position, 'project_id' => $project->id,
            'status' => 'active', 'valid_from' => now()->subMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        return [$employee, $assignment];
    }

    private function medical(int $organizationId, int $employeeId): SafetyMedicalExam
    {
        return SafetyMedicalExam::withoutEvents(fn (): SafetyMedicalExam => SafetyMedicalExam::query()->create(['organization_id' => $organizationId,
            'employee_id' => $employeeId, 'exam_type' => 'periodic', 'completed_at' => now()->subMonth()->toDateString(),
            'valid_until' => now()->addMonths(11)->toDateString(), 'result' => 'fit', 'restrictions' => 'private-medical-notes']));
    }

    private function medicalReference(SafetyMedicalExam $exam): array
    {
        return ['organization_id' => $exam->organization_id, 'source_type' => 'operations_safety_medical', 'entity_type' => 'safety_medical_exam', 'entity_id' => $exam->id];
    }

    private function material(Organization $organization): Material
    {
        return Material::withoutEvents(fn (): Material => Material::query()->create(['organization_id' => $organization->id, 'name' => 'Кабель', 'is_active' => true]));
    }

    private function inventory(AssistantRealAuthorizationFixture $fixture, Project $project, string $code, ?Organization $organization = null): InventoryAct
    {
        $organization ??= $fixture->organization;
        return Model::withoutEvents(function () use ($fixture, $project, $code, $organization): InventoryAct {
            $warehouse = OrganizationWarehouse::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Склад', 'code' => $code, 'warehouse_type' => 'project', 'is_active' => true]);
            return InventoryAct::query()->create(['organization_id' => $organization->id, 'warehouse_id' => $warehouse->id, 'act_number' => 'INV-'.$organization->id.'-'.$code,
                'inventory_date' => now()->toDateString(), 'status' => 'draft', 'created_by' => $fixture->owner->id]);
        });
    }

    private function item(int $actId, int $materialId, string $batch): InventoryActItem
    {
        return InventoryActItem::withoutEvents(fn (): InventoryActItem => InventoryActItem::query()->create(['inventory_act_id' => $actId, 'material_id' => $materialId,
            'expected_quantity' => '2.000', 'actual_quantity' => '2.125', 'unit_price' => '987654.32', 'total_value' => '2098765.43', 'batch_number' => $batch]));
    }
}

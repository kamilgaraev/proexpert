<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ConstructionJournal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstructionJournal\ConstructionJournalFormOptionsService;
use DomainException;
use Illuminate\Support\Facades\Event;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class ConstructionJournalEstimateResourcesTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->mock(AuthorizationService::class, static function ($mock): void {
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
        Event::fake([
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryCreated::class,
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalWorkVolumesRecorded::class,
        ]);
    }

    public function test_confirmed_excel_lsr_composition_keeps_five_resources_and_original_work_basis(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $options = app(ConstructionJournalFormOptionsService::class)->build($actor, $journal);
        $item = $options['estimates'][0]['items'][0];

        self::assertSame(0.035, $item['estimate_planned_quantity']);
        self::assertSame('100 м2', $item['measurementUnit']['short_name']);
        self::assertCount(5, $item['resources']);
        self::assertSame(['labor', 'equipment', 'material', 'material', 'material'], array_column($item['resources'], 'resource_type'));
        self::assertSame([121.0, 4.11, 0.3, 0.0023, 0.016], array_column($item['resources'], 'quantity_per_unit'));
        self::assertSame([4.235, 0.14385, 0.0105, 0.0000805, 0.00056], array_column($item['resources'], 'total_quantity'));
        foreach ($item['resources'] as $resource) {
            self::assertLessThan(0, $resource['id']);
            self::assertSame('estimate_item:'.$resource['estimate_item_id'], $resource['source_key']);
            self::assertSame($organization->id, $children->firstWhere('id', $resource['estimate_item_id'])->measurementUnit->organization_id);
        }
    }

    public function test_unknown_xml_quantities_remain_composition_without_invented_norm_or_total(): void
    {
        [$organization, $actor, $journal, $parent] = $this->fixture();
        $parent->update(['metadata' => ['original_unit' => '100 м2', 'raw_data' => ['Quant' => 0.035]]]);
        $resources = $this->resources($actor, $journal);

        self::assertCount(5, $resources);
        foreach ($resources as $resource) {
            self::assertNull($resource['quantity_per_unit']);
            self::assertNull($resource['total_quantity']);
            self::assertNotEmpty($resource['unit_name']);
        }
    }

    public function test_legacy_excel_price_index_is_not_a_norm_and_explicit_metadata_stays_authoritative(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $parent->update(['metadata' => ['original_unit' => '100 м2', 'raw_data' => ['G' => 0.035, 'H' => '100 м2', 'I' => 1, 'J' => 500, 'K' => 0.035]]]);
        $water = $children->firstWhere('name', 'Вода');
        $water->update(['metadata' => [...$water->metadata, 'quantity_per_unit' => 0.3]]);
        $resources = $this->resources($actor, $journal);

        foreach ($resources as $resource) {
            if ($resource['estimate_item_id'] === $water->id) {
                self::assertSame(0.3, $resource['quantity_per_unit']);
                self::assertSame(0.0105, $resource['total_quantity']);
            } else {
                self::assertNull($resource['quantity_per_unit']);
                self::assertNull($resource['total_quantity']);
            }
        }
    }

    public function test_one_malformed_child_rejects_excel_shape_without_epsilon_or_partial_inference(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $forging = $children->firstWhere('name', 'Поковки');
        $metadata = $forging->metadata;
        $metadata['raw_data']['K'] = 0.00008051;
        $forging->update(['metadata' => $metadata]);

        foreach ($this->resources($actor, $journal) as $resource) {
            self::assertNull($resource['quantity_per_unit']);
            self::assertNull($resource['total_quantity']);
        }
    }

    public function test_explicit_known_zero_norm_takes_precedence_without_becoming_unknown(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $water = $children->firstWhere('name', 'Вода');
        $water->update(['metadata' => [...$water->metadata, 'quantity_per_unit' => 0]]);
        $resource = collect($this->resources($actor, $journal))->firstWhere('estimate_item_id', $water->id);

        self::assertSame(0.0, $resource['quantity_per_unit']);
        self::assertSame(0.0, $resource['total_quantity']);
    }

    public function test_canonical_rows_take_precedence_and_do_not_duplicate_represented_or_matching_children(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $water = $children->firstWhere('name', 'Вода');
        $labor = $children->firstWhere('name', 'Средний разряд работы 3,0');
        $canonical = EstimateItemResource::query()->create([
            'estimate_item_id' => $parent->id,
            'resource_type' => 'material',
            'name' => 'Каноническая вода',
            'measurement_unit_id' => $water->measurement_unit_id,
            'quantity_per_unit' => 0.5,
            'total_quantity' => 0.0175,
            'represented_by_item_id' => $water->id,
        ]);
        EstimateItemResource::query()->create([
            'estimate_item_id' => $parent->id,
            'resource_type' => 'labor',
            'name' => $labor->name,
            'measurement_unit_id' => $labor->measurement_unit_id,
            'quantity_per_unit' => 121,
            'total_quantity' => 4.235,
        ]);
        $resources = $this->resources($actor, $journal);

        self::assertCount(5, $resources);
        $canonicalWater = collect($resources)->firstWhere('source_key', 'estimate_item_resource:'.$canonical->id);
        self::assertNotNull($canonicalWater);
        self::assertSame($canonical->id, $canonicalWater['id']);
        self::assertSame($water->id, $canonicalWater['estimate_item_id']);
        self::assertSame(0.5, $canonicalWater['quantity_per_unit']);
        self::assertSame(0.0175, $canonicalWater['total_quantity']);
        self::assertNotContains('estimate_item:'.$water->id, array_column($resources, 'source_key'));
        self::assertNotContains('estimate_item:'.$labor->id, array_column($resources, 'source_key'));
    }

    public function test_extreme_explicit_norm_does_not_emit_an_infinite_computed_total(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $parent->update(['quantity' => 100, 'quantity_total' => 100]);
        $water = $children->firstWhere('name', 'Вода');
        $water->update(['metadata' => [...$water->metadata, 'quantity_per_unit' => 1.0e308]]);
        $resources = $this->resources($actor, $journal);
        $resource = collect($resources)->firstWhere('estimate_item_id', $water->id);

        self::assertTrue(is_finite($resource['quantity_per_unit']));
        self::assertNull($resource['total_quantity']);
        self::assertIsString(json_encode($resources, JSON_THROW_ON_ERROR));
    }

    public function test_child_estimate_scope_and_foreign_unit_and_material_ids_are_not_exposed(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignEstimate = Estimate::query()->create([
            'organization_id' => $foreignOrganization->id,
            'project_id' => $journal->project_id,
            'name' => 'Чужая смета',
            'number' => 'FOREIGN-RESOURCE',
            'estimate_date' => '2026-10-11',
            'status' => 'approved',
        ]);
        $foreignChild = EstimateItem::query()->create([
            'estimate_id' => $foreignEstimate->id,
            'parent_work_id' => $parent->id,
            'position_number' => '999',
            'item_type' => 'material',
            'name' => 'Чужая позиция',
            'quantity' => 1,
        ]);
        $foreignUnit = MeasurementUnit::query()->where('organization_id', $foreignOrganization->id)->where('short_name', 'м³')->firstOrFail();
        $foreignMaterial = Material::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Чужой каталог',
            'code' => 'FOREIGN-RESOURCE-CATALOG',
            'measurement_unit_id' => $foreignUnit->id,
        ]);
        $water = $children->firstWhere('name', 'Вода');
        $water->update(['measurement_unit_id' => $foreignUnit->id, 'material_id' => $foreignMaterial->id]);
        $resources = $this->resources($actor, $journal);
        self::assertNotContains($foreignChild->id, array_column($resources, 'estimate_item_id'));
        $mappedWater = collect($resources)->firstWhere('estimate_item_id', $water->id);
        self::assertNull($mappedWater['measurementUnit']);
        self::assertNull($mappedWater['measurement_unit_id']);
        self::assertNull($mappedWater['material_id']);
        self::assertNull($mappedWater['quantity_per_unit']);
        $this->expectException(DomainException::class);
        app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $parent->estimate_id,
            'entry_date' => '2026-10-11', 'work_description' => 'Подмена единицы или каталога',
            'work_volumes' => [['estimate_item_id' => $parent->id, 'quantity' => 0.035]],
            'materials' => [['estimate_item_id' => $water->id, 'material_name' => 'Вода', 'quantity' => 0.0105, 'measurement_unit' => 'м3']],
        ], $actor);
    }

    public function test_save_and_reopen_preserve_eight_decimal_facts_and_manual_edits_without_reseeding(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $resources = $this->resources($actor, $journal);
        $labor = collect($resources)->firstWhere('resource_type', 'labor');
        $machine = collect($resources)->firstWhere('resource_type', 'equipment');
        $materials = collect($resources)->where('resource_type', 'material')->map(static fn (array $resource): array => [
            'estimate_item_id' => $resource['estimate_item_id'],
            'material_name' => $resource['name'],
            'quantity' => $resource['total_quantity'],
            'measurement_unit' => $resource['unit_name'],
        ])->values()->all();
        $service = app(ConstructionJournalService::class);
        $entry = $service->createEntry($journal, [
            'estimate_id' => $parent->estimate_id,
            'entry_date' => '2026-10-11',
            'work_description' => 'Кладка перегородок',
            'work_volumes' => [['estimate_item_id' => $parent->id, 'quantity' => 0.035]],
            'workers' => [['estimate_item_id' => $labor['estimate_item_id'], 'specialty' => 'Каменщик', 'workers_count' => 2, 'hours_worked' => $labor['total_quantity']]],
            'equipment' => [['estimate_item_id' => $machine['estimate_item_id'], 'equipment_name' => $machine['name'], 'quantity' => 1, 'hours_used' => $machine['total_quantity']]],
            'materials' => $materials,
        ], $actor);
        $entry = $entry->fresh(ConstructionJournalPayloadService::ENTRY_RELATIONS);
        $payload = app(ConstructionJournalPayloadService::class)->mapEntry($entry, $actor);
        self::assertSame(4.235, $payload['workers'][0]['hours_worked']);
        self::assertSame(0.14385, $payload['equipment'][0]['hours_used']);
        self::assertSame([0.0105, 0.0000805, 0.00056], array_column($payload['materials'], 'quantity'));
        $rowIds = array_column($payload['materials'], 'id');
        $updatedMaterials = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'estimate_item_id' => $row['estimate_item_id'],
            'material_name' => $row['material_name'], 'measurement_unit' => $row['measurement_unit'],
            'quantity' => $row['material_name'] === 'Поковки' ? 0.00009 : $row['quantity'],
        ], $payload['materials']);
        $entry = $service->updateEntry($entry, ['materials' => $updatedMaterials], $actor);
        $reopened = app(ConstructionJournalPayloadService::class)->mapEntry($entry->fresh(ConstructionJournalPayloadService::ENTRY_RELATIONS), $actor);
        self::assertSame($rowIds, array_column($reopened['materials'], 'id'));
        self::assertSame(0.00009, collect($reopened['materials'])->firstWhere('material_name', 'Поковки')['quantity']);
        self::assertSame(0.0000805, collect($reopened['workVolumes'][0]['estimateItem']['resources'])->firstWhere('name', 'Поковки')['total_quantity']);
    }

    public function test_resource_child_of_a_different_work_is_rejected_before_fact_mutation(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $otherParent = EstimateItem::query()->create([
            'estimate_id' => $parent->estimate_id, 'position_number' => '200', 'item_type' => 'work',
            'name' => 'Другая работа', 'measurement_unit_id' => $parent->measurement_unit_id, 'quantity' => 1,
        ]);
        $water = $children->firstWhere('name', 'Вода');
        $water->update(['parent_work_id' => $otherParent->id]);
        $this->expectException(DomainException::class);
        app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $parent->estimate_id,
            'entry_date' => '2026-10-11', 'work_description' => 'Подмена ресурса',
            'work_volumes' => [['estimate_item_id' => $parent->id, 'quantity' => 0.035]],
            'materials' => [['estimate_item_id' => $water->id, 'material_name' => 'Вода', 'quantity' => 0.0105, 'measurement_unit' => 'м3']],
        ], $actor);
    }

    public function test_partial_parent_work_change_cannot_keep_orphaned_saved_manual_resources(): void
    {
        [$organization, $actor, $journal, $parent, $children] = $this->fixture();
        $water = $children->firstWhere('name', 'Вода');
        $service = app(ConstructionJournalService::class);
        $entry = $service->createEntry($journal, [
            'estimate_id' => $parent->estimate_id, 'entry_date' => '2026-10-11', 'work_description' => 'Ручной расход',
            'work_volumes' => [['estimate_item_id' => $parent->id, 'quantity' => 0.035]],
            'materials' => [['estimate_item_id' => $water->id, 'material_name' => 'Вода', 'quantity' => 0.02, 'measurement_unit' => 'м3']],
        ], $actor);
        $otherParent = EstimateItem::query()->create([
            'estimate_id' => $parent->estimate_id, 'position_number' => '200', 'item_type' => 'work',
            'name' => 'Другая работа', 'measurement_unit_id' => $parent->measurement_unit_id, 'quantity' => 1,
        ]);
        try {
            $service->updateEntry($entry, ['work_volumes' => [['estimate_item_id' => $otherParent->id, 'quantity' => 1]]], $actor);
            self::fail('Нельзя оставить ручной ресурс прежней работы после частичной замены');
        } catch (DomainException) {
            self::assertSame($parent->id, $entry->fresh()->workVolumes()->firstOrFail()->estimate_item_id);
            self::assertSame(0.02, (float) $entry->fresh()->materials()->firstOrFail()->quantity);
        }
        $entry = $service->updateEntry($entry->fresh(), ['work_volumes' => [['estimate_item_id' => $otherParent->id, 'quantity' => 1]], 'materials' => []], $actor);
        self::assertSame($otherParent->id, $entry->workVolumes()->firstOrFail()->estimate_item_id);
        self::assertSame(0, $entry->materials()->count());
    }

    public function test_scaled_length_and_proven_piece_stock_are_materials_while_piece_machines_do_not_become_fake_hours(): void
    {
        [$organization, $actor, $journal, $parent] = $this->fixture();
        $cable = $this->semanticChild($organization, $parent, 'Канат', '10 м', '08.2.02.11-0007', 1.25);
        $staple = $this->semanticChild($organization, $parent, 'Кровельный костыль', 'шт', '08.1.02.25-0082', 2);
        $machine = $this->semanticChild($organization, $parent, 'Машина в штуках', 'шт', '91.05.01-017', 1);
        $distanceMachine = $this->semanticChild($organization, $parent, 'Пробег машины', '10 м', '91.05.01-017', 1.5);
        $laborCount = $this->semanticChild($organization, $parent, 'Численность', 'чел', '1-100-30', 3, type: 'labor');
        $resources = collect($this->resources($actor, $journal));
        $mappedCable = $resources->firstWhere('estimate_item_id', $cable->id);
        $mappedStaple = $resources->firstWhere('estimate_item_id', $staple->id);
        $mappedMachine = $resources->firstWhere('estimate_item_id', $machine->id);
        $mappedDistanceMachine = $resources->firstWhere('estimate_item_id', $distanceMachine->id);
        $mappedLabor = $resources->firstWhere('estimate_item_id', $laborCount->id);

        self::assertSame('material', $mappedCable['resource_type']);
        self::assertSame('10 м', $mappedCable['unit_name']);
        self::assertSame(1.25, $mappedCable['quantity_per_unit']);
        self::assertSame(0.04375, $mappedCable['total_quantity']);
        self::assertSame('material', $mappedStaple['resource_type']);
        self::assertSame(2.0, $mappedStaple['quantity_per_unit']);
        self::assertSame(0.07, $mappedStaple['total_quantity']);
        self::assertSame('equipment', $mappedMachine['resource_type']);
        self::assertNull($mappedMachine['quantity_per_unit']);
        self::assertSame(0.035, $mappedMachine['total_quantity']);
        self::assertSame('equipment', $mappedDistanceMachine['resource_type']);
        self::assertSame('10 м', $mappedDistanceMachine['unit_name']);
        self::assertNull($mappedDistanceMachine['quantity_per_unit']);
        self::assertSame(0.0525, $mappedDistanceMachine['total_quantity']);
        self::assertSame('labor', $mappedLabor['resource_type']);
        self::assertNull($mappedLabor['quantity_per_unit']);
        self::assertSame(0.105, $mappedLabor['total_quantity']);

        $entry = app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $parent->estimate_id, 'entry_date' => '2026-10-11', 'work_description' => 'Физические ресурсы',
            'work_volumes' => [['estimate_item_id' => $parent->id, 'quantity' => 0.035]],
            'materials' => [
                ['estimate_item_id' => $cable->id, 'material_name' => $cable->name, 'quantity' => 0.04375, 'measurement_unit' => '10 м'],
                ['estimate_item_id' => $staple->id, 'material_name' => $staple->name, 'quantity' => 0.07, 'measurement_unit' => 'шт'],
            ],
        ], $actor);
        self::assertSame(0.04375, (float) $entry->materials()->where('estimate_item_id', $cable->id)->firstOrFail()->quantity);
        self::assertSame(0, $entry->equipment()->count());
        self::assertSame(0, $entry->workers()->count());
    }

    public function test_piece_stock_provenance_requires_matching_raw_code_and_scoped_unit(): void
    {
        [$organization, $actor, $journal, $parent] = $this->fixture();
        $item = $this->semanticChild($organization, $parent, 'Недоказанные штуки', 'шт', '08.1.02.25-0082', 2);
        $metadata = $item->metadata;
        $metadata['raw_data']['B'] = '91.05.01-017';
        $item->update(['metadata' => $metadata]);
        $mapped = collect($this->resources($actor, $journal))->firstWhere('estimate_item_id', $item->id);

        self::assertSame('equipment', $mapped['resource_type']);
        self::assertNull($mapped['quantity_per_unit']);
        $this->expectException(DomainException::class);
        app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $parent->estimate_id, 'entry_date' => '2026-10-11', 'work_description' => 'Подмена категории',
            'work_volumes' => [['estimate_item_id' => $parent->id, 'quantity' => 0.035]],
            'materials' => [['estimate_item_id' => $item->id, 'material_name' => $item->name, 'quantity' => 0.07, 'measurement_unit' => 'шт']],
        ], $actor);
    }

    public function test_canonical_equipment_projection_uses_proven_material_child_with_matching_unit_and_does_not_duplicate_it(): void
    {
        [$organization, $actor, $journal, $parent] = $this->fixture();
        $staple = $this->semanticChild($organization, $parent, 'Костыль canonical', 'шт', '08.1.02.25-0082', 2);
        $canonical = EstimateItemResource::query()->create([
            'estimate_item_id' => $parent->id, 'resource_type' => 'equipment', 'name' => $staple->name,
            'measurement_unit_id' => $staple->measurement_unit_id, 'quantity_per_unit' => 2,
            'total_quantity' => 0.07, 'represented_by_item_id' => $staple->id,
        ]);
        $resources = collect($this->resources($actor, $journal));
        $mapped = $resources->firstWhere('source_key', 'estimate_item_resource:'.$canonical->id);

        self::assertSame('material', $mapped['resource_type']);
        self::assertSame(2.0, $mapped['quantity_per_unit']);
        self::assertSame(0.07, $mapped['total_quantity']);
        self::assertSame($staple->id, $mapped['estimate_item_id']);
        self::assertCount(6, $resources);
        self::assertNull($resources->firstWhere('source_key', 'estimate_item:'.$staple->id));
    }

    private function semanticChild(Organization $organization, EstimateItem $parent, string $name, string $unit, string $code, float $norm, string $type = 'equipment'): EstimateItem
    {
        $total = round($norm * 0.035, 8);

        return EstimateItem::query()->create([
            'estimate_id' => $parent->estimate_id, 'parent_work_id' => $parent->id,
            'position_number' => '1.'.(100 + $parent->childItems()->count()), 'item_type' => $type,
            'name' => $name, 'normative_rate_code' => $code, 'measurement_unit_id' => $this->unit($organization, $unit)->id,
            'quantity' => $total, 'metadata' => [
                'original_unit' => $unit, 'is_informative_row' => false,
                'raw_data' => ['B' => $code, 'H' => $unit, 'I' => $norm, 'J' => null, 'K' => $total],
            ],
        ]);
    }

    private function resources(User $actor, ConstructionJournal $journal): array
    {
        return app(ConstructionJournalFormOptionsService::class)->build($actor, $journal)['estimates'][0]['items'][0]['resources'];
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'performing_organization_id' => $organization->id, 'name' => 'Журнал', 'journal_number' => 'RESOURCES',
            'start_date' => '2026-10-11', 'status' => 'active', 'created_by_user_id' => $actor->id,
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Excel ЛСР',
            'number' => 'EXCEL-RESOURCE', 'estimate_date' => '2026-10-11', 'status' => 'approved',
        ]);
        $workUnit = $this->unit($organization, '100 м2');
        $parent = EstimateItem::query()->create([
            'estimate_id' => $estimate->id, 'position_number' => '1', 'item_type' => 'work',
            'name' => 'Кладка перегородок из кирпича', 'measurement_unit_id' => $workUnit->id,
            'quantity' => 0.035, 'quantity_total' => 0.035,
            'metadata' => ['original_unit' => '100 м2', 'raw_data' => ['H' => '100 м2', 'I' => 0.035, 'J' => 1, 'K' => 0.035]],
        ]);
        $rows = [
            ['labor', 'ОТ(ЗТ)', 'чел.-ч', null, 4.235, true],
            ['labor', 'Средний разряд работы 3,0', 'чел.-ч', 121, 4.235, false],
            ['equipment', 'ЭМ', null, null, null, true],
            ['labor', 'ОТм(ЗТм)', 'чел.-ч', null, 0.14385, true],
            ['machinery', 'Кран', 'маш.час', 4.11, 0.14385, false],
            ['labor', 'ОТм(ЗТм) Машинисты', 'чел.-ч', 4.11, 0.14385, true],
            ['material', 'М', null, null, null, true],
            ['material', 'Вода', 'м3', 0.3, 0.0105, false],
            ['equipment', 'Поковки', 'т', 0.0023, 0.0000805, false],
            ['material', 'Бруски', 'м3', 0.016, 0.00056, false],
        ];
        $children = collect();
        foreach ($rows as $index => [$type, $name, $unit, $norm, $total, $informative]) {
            $children->push(EstimateItem::query()->create([
                'estimate_id' => $estimate->id, 'parent_work_id' => $parent->id, 'position_number' => '1.'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'item_type' => $type, 'name' => $name, 'measurement_unit_id' => $unit ? $this->unit($organization, $unit)->id : null,
                'quantity' => $total ?? 0, 'quantity_total' => null,
                'metadata' => ['original_unit' => $unit, 'is_informative_row' => $informative, 'raw_data' => ['H' => $unit, 'I' => $norm, 'J' => null, 'K' => $total]],
            ]));
        }

        return [$organization, $actor, $journal, $parent, $children];
    }

    private function unit(Organization $organization, string $shortName): MeasurementUnit
    {
        return MeasurementUnit::query()->firstOrCreate(['organization_id' => $organization->id, 'short_name' => $shortName], ['name' => $shortName, 'type' => 'material']);
    }
}

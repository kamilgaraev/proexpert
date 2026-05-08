<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Features\BudgetEstimates\Services\Normative\EstimateNormativeCatalogService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Repositories\EstimateItemRepository;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class EstimateNormativeCatalogServiceTest extends TestCase
{
    public function test_search_returns_new_estimate_normatives_with_price_summary(): void
    {
        [$normId] = $this->seedNormative();

        $results = app(EstimateNormativeCatalogService::class)->search([
            'query' => '01-01',
            'per_page' => 10,
        ]);

        $this->assertSame(1, $results->total());
        $this->assertSame($normId, $results->items()[0]['id']);
        $this->assertSame('01-01-001-01', $results->items()[0]['code']);
    }

    public function test_add_items_from_normatives_creates_work_and_resource_children(): void
    {
        [$normId] = $this->seedNormative();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'T-1',
            'name' => 'Смета',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
            'calculation_method' => 'resource',
        ]);

        $calculationService = Mockery::mock(EstimateCalculationService::class);
        $calculationService->shouldReceive('calculateItemTotal')->andReturn(0.0);
        $calculationService->shouldReceive('calculateEstimateTotal')->andReturn([]);
        $service = new EstimateNormativeCatalogService(app(EstimateItemRepository::class), $calculationService);

        $items = $service->addItemsFromNormatives($estimate, [[
            'estimate_norm_id' => $normId,
            'quantity' => 2,
            'position_number' => '1',
        ]]);

        $this->assertCount(1, $items);
        $work = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->where('item_type', EstimatePositionItemType::WORK->value)
            ->firstOrFail();
        $resource = EstimateItem::query()
            ->where('parent_work_id', $work->id)
            ->firstOrFail();

        $this->assertSame('01-01-001-01', $work->normative_rate_code);
        $this->assertSame('estimate_norms', $work->metadata['normative_source']);
        $this->assertSame('01.1.01.01-0001', $resource->normative_rate_code);
        $this->assertSame(3.0, (float) $resource->quantity);
        $this->assertSame(3000.0, (float) $resource->total_amount);
        $this->assertSame(1, EstimateItem::query()->where('parent_work_id', $work->id)->count());
        $this->assertDatabaseMissing('estimate_items', [
            'parent_work_id' => $work->id,
            'name' => '2',
        ]);
    }

    public function test_add_items_uses_prices_from_fsnb_version_when_separate_fsbc_version_is_absent(): void
    {
        [$normId] = $this->seedNormative(false);
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'T-2',
            'name' => 'Смета',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
            'calculation_method' => 'resource',
        ]);

        $calculationService = Mockery::mock(EstimateCalculationService::class);
        $calculationService->shouldReceive('calculateItemTotal')->andReturn(0.0);
        $calculationService->shouldReceive('calculateEstimateTotal')->andReturn([]);
        $service = new EstimateNormativeCatalogService(app(EstimateItemRepository::class), $calculationService);

        $service->addItemsFromNormatives($estimate, [[
            'estimate_norm_id' => $normId,
            'quantity' => 2,
            'position_number' => '1',
        ]]);

        $work = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->where('item_type', EstimatePositionItemType::WORK->value)
            ->firstOrFail();
        $resource = EstimateItem::query()
            ->where('parent_work_id', $work->id)
            ->firstOrFail();

        $this->assertSame(1000.0, (float) $resource->unit_price);
        $this->assertSame(3000.0, (float) $resource->total_amount);
        $this->assertSame('fsnb_2022', $work->metadata['price_dataset']['source_type']);
    }

    public function test_add_items_splits_machine_price_and_machinist_labor_from_fsbc_price(): void
    {
        $fsnbVersionId = $this->createVersion('fsnb_2022', '2026-05-07');
        $fsbcVersionId = $this->createVersion('fsbc', '2026-05-07');
        $collectionId = $this->createCollection($fsnbVersionId);
        $sectionId = $this->createSection($collectionId);
        $normId = $this->createNorm($collectionId, $sectionId);

        DB::table('estimate_norm_resources')->insert([
            [
                'estimate_norm_id' => $normId,
                'construction_resource_id' => null,
                'resource_code' => '2',
                'resource_name' => 'ЭМ',
                'unit' => null,
                'quantity' => 0,
                'resource_type' => 'summary',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'estimate_norm_id' => $normId,
                'construction_resource_id' => null,
                'resource_code' => '91.14.02-001',
                'resource_name' => 'Автомобили бортовые, грузоподъемность до 5 т',
                'unit' => 'маш.-ч',
                'quantity' => 0.01,
                'resource_type' => 'machine',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('estimate_resource_prices')->insert([
            'dataset_version_id' => $fsbcVersionId,
            'construction_resource_id' => null,
            'resource_code' => '91.14.02-001',
            'resource_name' => 'Автомобили бортовые, грузоподъемность до 5 т',
            'unit' => 'маш.-ч',
            'base_price' => 814.35,
            'machine_salary_price' => 336.43,
            'machine_price_without_salary' => 477.92,
            'machine_labor_quantity' => 1,
            'driver_code' => '4-100-040',
            'machinist_category' => '4.0',
            'price_type' => 'machine',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'T-3',
            'name' => 'Смета',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
            'calculation_method' => 'resource',
        ]);

        $calculationService = Mockery::mock(EstimateCalculationService::class);
        $calculationService->shouldReceive('calculateItemTotal')->andReturn(0.0);
        $calculationService->shouldReceive('calculateEstimateTotal')->andReturn([]);
        $service = new EstimateNormativeCatalogService(app(EstimateItemRepository::class), $calculationService);

        $service->addItemsFromNormatives($estimate, [[
            'estimate_norm_id' => $normId,
            'quantity' => 1,
            'position_number' => '1',
        ]]);

        $work = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->where('item_type', EstimatePositionItemType::WORK->value)
            ->firstOrFail();
        $children = EstimateItem::query()
            ->where('parent_work_id', $work->id)
            ->orderBy('position_number')
            ->get();

        $this->assertCount(2, $children);
        $this->assertSame('91.14.02-001', $children[0]->normative_rate_code);
        $this->assertSame(477.92, (float) $children[0]->unit_price);
        $this->assertSame(4.78, (float) $children[0]->total_amount);
        $this->assertSame('4-100-040', $children[1]->normative_rate_code);
        $this->assertSame(EstimatePositionItemType::LABOR->value, $children[1]->item_type->value);
        $this->assertSame(0.01, (float) $children[1]->quantity);
        $this->assertSame(336.43, (float) $children[1]->unit_price);
        $this->assertSame(3.36, (float) $children[1]->total_amount);
        $this->assertSame(8.14, (float) $work->direct_costs);
    }

    private function seedNormative(bool $separatePriceVersion = true): array
    {
        $fsnbVersionId = $this->createVersion('fsnb_2022', '2026-05-07');
        $priceVersionId = $separatePriceVersion
            ? $this->createVersion('fsbc', '2026-05-07')
            : $fsnbVersionId;
        $collectionId = $this->createCollection($fsnbVersionId);
        $sectionId = $this->createSection($collectionId);
        $normId = $this->createNorm($collectionId, $sectionId);

        DB::table('estimate_norm_resources')->insert([
            [
                'estimate_norm_id' => $normId,
                'construction_resource_id' => null,
                'resource_code' => '2',
                'resource_name' => 'М',
                'unit' => null,
                'quantity' => 0,
                'resource_type' => 'summary',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'estimate_norm_id' => $normId,
                'construction_resource_id' => null,
                'resource_code' => '01.1.01.01-0001',
                'resource_name' => 'Бетон тяжелый',
                'unit' => 'м3',
                'quantity' => 1.5,
                'resource_type' => 'material',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('estimate_resource_prices')->insert([
            'dataset_version_id' => $priceVersionId,
            'construction_resource_id' => null,
            'resource_code' => '01.1.01.01-0001',
            'resource_name' => 'Бетон тяжелый',
            'unit' => 'м3',
            'base_price' => 1000,
            'price_type' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$normId];
    }

    private function createVersion(string $sourceType, string $versionKey): int
    {
        return (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => $sourceType,
            'version_key' => $versionKey,
            'bucket' => 'test-bucket',
            'prefix' => 'test-prefix',
            'status' => 'parsed',
            'files_count' => 1,
            'rows_read' => 1,
            'rows_imported' => 1,
            'errors_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCollection(int $versionId): int
    {
        return (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $versionId,
            'code' => 'gesn',
            'name' => 'ГЭСН',
            'norm_type' => 'gesn',
            'source_file' => 'ГЭСН.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSection(int $collectionId): int
    {
        return (int) DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collectionId,
            'parent_id' => null,
            'code' => '01',
            'name' => 'Фундаменты',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => '01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNorm(int $collectionId, int $sectionId): int
    {
        return (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'section_id' => $sectionId,
            'code' => '01-01-001-01',
            'name' => 'Бетонирование фундаментов',
            'unit' => 'м3',
            'section_code' => '01-01-001',
            'section_name' => 'Фундаменты',
            'work_composition' => json_encode(['Укладка бетонной смеси'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

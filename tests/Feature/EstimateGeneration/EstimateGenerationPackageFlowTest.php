<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;

class EstimateGenerationPackageFlowTest extends TestCase
{
    public function test_generation_persists_local_estimate_packages_and_dense_items(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'created',
            'processing_stage' => 'created',
            'processing_progress' => 0,
            'input_payload' => [
                'description' => 'Дом 150 м2, 2 этажа, 8 комнат, Татарстан, 1 квартал 2026',
                'building_type' => 'Жилой',
                'area' => 150,
                'regional_context' => [
                    'region_name' => 'Республика Татарстан',
                    'year' => 2026,
                    'quarter' => 1,
                    'version_key' => '2026-q1-ru-ta',
                ],
            ],
            'problem_flags' => [],
        ]);

        $session = app(EstimateGenerationOrchestrator::class)->generate($session);

        $this->assertContains($session->status, ['ready_for_review', 'review_required', 'blocked']);
        $this->assertGreaterThanOrEqual(15, $session->packages()->count());
        $this->assertGreaterThanOrEqual(60, $session->packages()->withCount('items')->get()->sum('items_count'));
        $this->assertGreaterThan(0, $session->packages()->get()->sum(fn ($package): int => (int) ($package->totals['priced_items_count'] ?? 0)));
        $this->assertSame(0, $session->packages()->get()->sum(fn ($package): int => (int) ($package->totals['operation_items_count'] ?? 0)));
        $this->assertDatabaseMissing('estimate_generation_package_items', [
            'item_type' => 'priced_work',
            'unit' => 'компл',
            'quantity' => 0.080000,
        ]);
        $this->assertNotSame('generated', $session->status);
        $this->assertNotEmpty($session->draft_payload['object_profile'] ?? []);
        $this->assertNotEmpty($session->draft_payload['package_plan'] ?? []);
        $this->assertDatabaseMissing('estimate_generation_package_items', [
            'item_type' => 'operation',
        ]);
    }

    public function test_mixed_office_warehouse_generation_persists_enough_priced_work_items(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'created',
            'processing_stage' => 'created',
            'processing_progress' => 0,
            'input_payload' => [
                'description' => 'Нужно сделать смету на небольшой двухэтажный офисно-складской корпус 780 м2 в Татарстане. На первом этаже склад 420 м2 с промышленным бетонным полом, разгрузочной зоной, воротами, пожарной сигнализацией и освещением. На втором этаже офисы 260 м2, переговорная, санузлы, серверная и лестничная клетка. Нужна входная группа, фасад из сэндвич-панелей, плоская кровля, отопление, вентиляция, электрика, водоснабжение, канализация, наружная площадка и подъезд для грузового транспорта.',
                'building_type' => 'Производственное',
                'area' => 780,
                'regional_context' => [
                    'region_name' => 'Республика Татарстан',
                    'year' => 2026,
                    'quarter' => 1,
                    'version_key' => '2026-q1-ru-ta',
                ],
            ],
            'problem_flags' => [],
        ]);

        $session = app(EstimateGenerationOrchestrator::class)->generate($session);
        $packages = $session->packages()->with('items')->get();
        $packageKeys = $packages->pluck('key')->all();
        $pricedItemsCount = $packages->sum(fn ($package): int => $package->items->where('item_type', 'priced_work')->count());

        $this->assertContains('office_partitions', $packageKeys);
        $this->assertContains('office_finishing', $packageKeys);
        $this->assertContains('sanitary_rooms', $packageKeys);
        $this->assertGreaterThanOrEqual(130, $pricedItemsCount);
        $this->assertGreaterThanOrEqual(130, $packages->sum(fn ($package): int => $package->items->count()));
        $this->assertSame(0, $packages->sum(fn ($package): int => $package->items->where('item_type', 'operation')->count()));
        $this->assertTrue($packages->flatMap->items->contains(
            fn ($item): bool => count($item->metadata['work_composition'] ?? []) >= 3
        ));
        $this->assertNotContains(
            'insufficient_detail',
            array_merge(...$packages->map(fn ($package): array => $package->quality_summary['critical_flags'] ?? [])->all())
        );
        $this->assertDatabaseMissing('estimate_generation_package_items', [
            'package_id' => $packages->firstWhere('key', 'industrial_floor')?->id,
            'item_type' => 'priced_work',
            'quantity' => 780.000000,
        ]);
    }
}

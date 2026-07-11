<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\Concerns\RunsEstimateGenerationPipeline;
use Tests\TestCase;

class EstimateGenerationPackageFlowTest extends TestCase
{
    use RunsEstimateGenerationPipeline;

    public function test_package_sync_persists_only_estimate_positions_and_prunes_old_service_rows(): void
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
            'status' => 'ready_to_apply',
            'processing_stage' => 'ready_to_apply',
            'processing_progress' => 100,
            'input_payload' => [],
            'problem_flags' => [],
        ]);
        $existingPackage = EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'key' => 'foundation',
            'title' => 'Foundation',
            'scope_type' => 'foundation',
            'status' => 'review_required',
            'generation_stage' => 'quality_check',
            'generation_progress' => 100,
            'target_items_min' => 0,
            'target_items_max' => 0,
            'actual_items_count' => 1,
            'totals' => [
                'total_cost' => 0,
                'operation_items_count' => 1,
            ],
            'quality_summary' => [],
            'assumptions' => [],
            'source_refs' => [],
            'metadata' => [],
            'sort_order' => 100,
            'finished_at' => now(),
        ]);
        EstimateGenerationPackageItem::query()->create([
            'package_id' => $existingPackage->id,
            'key' => 'foundation.old-operation',
            'item_type' => 'operation',
            'name' => 'Prepare work front',
            'total_cost' => 0,
            'metadata' => [],
            'sort_order' => 100,
        ]);

        app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, [
            'local_estimates' => [[
                'key' => 'foundation',
                'title' => 'Foundation',
                'scope_type' => 'foundation',
                'sections' => [[
                    'work_items' => [
                        $this->pricedWorkItem('foundation.concrete', 1200),
                        $this->pricedWorkItem('foundation.rebar', 800),
                        $this->pricedWorkItem('foundation.formwork', 600),
                        [
                            'key' => 'foundation.operation',
                            'item_type' => 'operation',
                            'name' => 'Prepare work front',
                            'total_cost' => 0,
                        ],
                        [
                            'key' => 'foundation.resource-note',
                            'item_type' => 'resource_note',
                            'name' => 'Resource note',
                            'total_cost' => 0,
                        ],
                        [
                            'key' => 'foundation.review-note',
                            'item_type' => 'review_note',
                            'name' => 'Review note',
                            'total_cost' => 0,
                        ],
                    ],
                ]],
            ]],
        ]);

        $package = $session->packages()->with('items')->firstWhere('key', 'foundation');

        $this->assertNotNull($package);
        $this->assertSame(3, $package->actual_items_count);
        $this->assertSame(3, $package->items->count());
        $this->assertSame(['priced_work'], $package->items->pluck('item_type')->unique()->values()->all());
        $this->assertSame(0, (int) ($package->totals['operation_items_count'] ?? -1));
        $this->assertSame(2600.0, (float) ($package->totals['total_cost'] ?? 0));
        $this->assertDatabaseMissing('estimate_generation_package_items', [
            'package_id' => $package->id,
            'item_type' => 'operation',
        ]);
        $this->assertDatabaseMissing('estimate_generation_package_items', [
            'package_id' => $package->id,
            'key' => 'foundation.old-operation',
        ]);
    }

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
            'status' => 'generating',
            'processing_stage' => 'generating',
            'processing_progress' => 0,
            'input_payload' => [
                'generation_attempt_id' => 'package-flow-1',
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

        $session = $this->runGenerationPipeline($session);

        $this->assertContains($session->status, [
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
        ]);
        $this->assertGreaterThanOrEqual(15, $session->packages()->count());
        $this->assertGreaterThanOrEqual(55, $session->packages()->withCount('items')->get()->sum('items_count'));
        $this->assertGreaterThan(0, $session->packages()->get()->sum(fn ($package): int => (int) ($package->totals['priced_items_count'] ?? 0)));
        $this->assertSame(0, $session->packages()->get()->sum(fn ($package): int => (int) ($package->totals['operation_items_count'] ?? 0)));
        $this->assertDatabaseMissing('estimate_generation_package_items', [
            'item_type' => 'priced_work',
            'unit' => 'компл',
            'quantity' => 0.080000,
        ]);
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
            'status' => 'generating',
            'processing_stage' => 'generating',
            'processing_progress' => 0,
            'input_payload' => [
                'generation_attempt_id' => 'package-flow-2',
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

        $session = $this->runGenerationPipeline($session);
        $packages = $session->packages()->with('items')->get();
        $packageKeys = $packages->pluck('key')->all();
        $pricedItemsCount = $packages->sum(fn ($package): int => $package->items->where('item_type', 'priced_work')->count());

        $this->assertContains('office_partitions', $packageKeys);
        $this->assertContains('office_finishing', $packageKeys);
        $this->assertContains('sanitary_rooms', $packageKeys);
        $this->assertGreaterThanOrEqual(75, $pricedItemsCount);
        $this->assertGreaterThanOrEqual(75, $packages->sum(fn ($package): int => $package->items->count()));
        $this->assertSame(0, $packages->sum(fn ($package): int => $package->items->where('item_type', 'operation')->count()));
        $this->assertTrue($packages->flatMap->items->contains(
            fn ($item): bool => count($item->metadata['work_composition'] ?? []) >= 3
        ));
        $this->assertDatabaseMissing('estimate_generation_package_items', [
            'package_id' => $packages->firstWhere('key', 'industrial_floor')?->id,
            'item_type' => 'priced_work',
            'quantity' => 780.000000,
        ]);
    }

    public function test_ocr_only_ready_document_drives_package_plan_quantities_and_source_refs(): void
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
            'status' => 'generating',
            'processing_stage' => 'generating',
            'processing_progress' => 0,
            'input_payload' => [
                'generation_attempt_id' => 'package-flow-3',
                'description' => '',
                'regional_context' => [
                    'region_name' => 'Республика Татарстан',
                    'year' => 2026,
                    'quarter' => 1,
                    'version_key' => '2026-q1-ru-ta',
                ],
            ],
            'problem_flags' => [],
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'filename' => 'warehouse-plan.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$organization->id.'/estimate-generation/sessions/'.$session->id.'/documents/warehouse-plan.pdf',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'extracted_text' => 'Складской корпус 1280 м2. Склад 900 м2. Офис 280 м2. 1 этаж. Плоская кровля.',
            'quality_score' => 0.91,
            'quality_level' => 'good',
            'quality_flags' => [],
            'facts_summary' => [
                'document_understanding' => [
                    'role_for_estimation' => 'drawing_architecture',
                    'extracted_capabilities' => [
                        'has_quantities' => true,
                        'requires_manual_review' => false,
                    ],
                ],
                'total_area_m2' => 1280.0,
                'floor_count' => 1.0,
                'zones' => [
                    ['scope_key' => 'warehouse_area', 'label' => 'Склад', 'area_m2' => 900.0],
                    ['scope_key' => 'office_area', 'label' => 'Офис', 'area_m2' => 280.0],
                ],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
        ]);
        EstimateGenerationDocumentFact::query()->create([
            'document_id' => $document->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'fact_type' => 'total_area',
            'scope_key' => 'total_area',
            'label' => 'Общая площадь',
            'value_text' => '1280 м2',
            'value_number' => 1280.0,
            'unit' => 'м2',
            'confidence' => 0.91,
            'source_ref' => [
                'type' => 'document',
                'document_id' => $document->id,
                'filename' => 'warehouse-plan.pdf',
                'page_number' => 1,
                'excerpt' => 'Складской корпус 1280 м2',
            ],
        ]);

        $session = $this->runGenerationPipeline($session);
        $packageKeys = $session->packages()->pluck('key')->all();
        $draft = $session->draft_payload;

        $this->assertContains('industrial_floor', $packageKeys);
        $this->assertContains('office_partitions', $packageKeys);
        $this->assertEquals(1280.0, $draft['object_profile']['area']);
        $this->assertSame($document->id, $draft['traceability']['document_source_refs'][0]['document_id']);
        $this->assertSame($document->id, $draft['local_estimates'][0]['source_refs'][0]['document_id']);
        $workItems = collect($draft['local_estimates'])
            ->flatMap(static fn (array $localEstimate) => $localEstimate['sections'] ?? [])
            ->flatMap(static fn (array $section) => $section['work_items'] ?? []);
        $sourceBackedItems = $workItems->filter(static fn (array $workItem): bool => collect($workItem['source_refs'] ?? [])
            ->contains(static fn (array $sourceRef): bool => ($sourceRef['document_id'] ?? null) === $document->id));

        $this->assertNotEmpty($sourceBackedItems->all());
        $this->assertTrue($sourceBackedItems->contains(static fn (array $workItem): bool => (float) ($workItem['quantity'] ?? 0) > 0));
        $this->assertNotSame(100.0, $draft['object_profile']['area']);
    }

    /**
     * @return array<string, mixed>
     */
    private function pricedWorkItem(string $key, float $totalCost): array
    {
        return [
            'key' => $key,
            'item_type' => 'priced_work',
            'name' => $key,
            'unit' => 'm3',
            'quantity' => 1,
            'price_source' => 'normative',
            'unit_price' => $totalCost,
            'materials_cost' => $totalCost,
            'labor_cost' => 0,
            'machinery_cost' => 0,
            'total_cost' => $totalCost,
            'normative_match' => [
                'status' => 'accepted',
                'confidence' => 0.95,
                'work_composition' => ['Operation stays inside norm composition'],
            ],
            'validation_flags' => [],
        ];
    }
}

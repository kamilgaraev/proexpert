<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact;
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
        $this->assertGreaterThanOrEqual(55, $session->packages()->withCount('items')->get()->sum('items_count'));
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
            'status' => 'created',
            'processing_stage' => 'created',
            'processing_progress' => 0,
            'input_payload' => [
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
            'storage_path' => 'org-' . $organization->id . '/estimate-generation/sessions/' . $session->id . '/documents/warehouse-plan.pdf',
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

        $session = app(EstimateGenerationOrchestrator::class)->generate($session);
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
}

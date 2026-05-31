<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemGenerationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StrictNormativeGenerationTest extends TestCase
{
    public function test_work_item_generation_does_not_create_market_resources(): void
    {
        $items = app(WorkItemGenerationService::class)->build([
            'key' => 'foundation',
            'title' => 'Фундамент',
            'scope_type' => 'foundation',
            'target_items_min' => 12,
        ], [
            'object' => [
                'area' => 150,
                'building_type' => 'Жилой',
            ],
        ]);

        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertSame([], $item['materials']);
            self::assertSame([], $item['labor']);
            self::assertSame([], $item['machinery']);
            self::assertSame([], $item['other_resources']);
            self::assertNotSame('market_estimate', $item['price_source'] ?? null);
            self::assertContains('normative_required', $item['validation_flags']);
        }
    }

    public function test_unmatched_norm_clears_any_preexisting_market_resources(): void
    {
        $items = app(ResourceAssemblyService::class)->enrich([[
            'key' => 'foundation-work-1',
            'name' => 'Непонятная пользовательская работа',
            'description' => 'Нет подходящей нормы',
            'work_category' => 'custom',
            'unit' => 'компл',
            'quantity' => 1,
            'confidence' => 0.4,
            'validation_flags' => ['normative_required'],
            'materials' => [[
                'name' => 'Материалы. Непонятная пользовательская работа',
                'source' => 'market_estimate',
                'total_price' => 1000,
            ]],
            'labor' => [[
                'name' => 'Работы. Непонятная пользовательская работа',
                'source' => 'market_estimate',
                'total_price' => 1000,
            ]],
            'machinery' => [],
            'other_resources' => [],
        ]], [
            'scope_type' => 'custom',
        ]);

        $item = $items[0];

        self::assertSame('not_found', $item['normative_match']['status']);
        self::assertSame([], $item['materials']);
        self::assertSame([], $item['labor']);
        self::assertSame([], $item['machinery']);
        self::assertContains('normative_not_found', $item['validation_flags']);
        self::assertContains('requires_normative_review', $item['validation_flags']);
        self::assertNotContains('market_price_used', $item['validation_flags']);
    }

    public function test_user_selected_candidate_replaces_review_item_with_normative_resources(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $normId = $this->seedNormativeWithPrice();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'review_required',
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'input_payload' => ['description' => 'Дом 150 м2'],
            'analysis_payload' => [],
            'draft_payload' => $this->draftPayloadWithCandidate($normId),
            'problem_flags' => [],
        ]);

        app(NormativeCandidateSelectionService::class)->select($session, 'foundation-work-1', $normId);

        $session->refresh();
        $item = $session->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('matched', $item['normative_match']['status']);
        self::assertSame('01-01-001-01', $item['normative_rate_code']);
        self::assertSame('01.1.01.01-0001', $item['materials'][0]['normative_ref']['resource_code']);
        self::assertEquals(2.0, $item['materials'][0]['quantity']);
        self::assertEquals(2000.0, $item['materials'][0]['total_price']);
        self::assertNotContains('requires_normative_review', $item['validation_flags']);
        self::assertSame(0, $session->draft_payload['quality_summary']['normative_items']['requires_review']);
    }

    public function test_apply_blocks_review_required_normative_candidates(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'review_required',
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'input_payload' => ['description' => 'Дом 150 м2'],
            'analysis_payload' => [],
            'draft_payload' => $this->draftPayloadWithCandidate(123),
            'problem_flags' => [],
        ]);

        try {
            app(EstimateDraftPersistenceService::class)->apply($session, ['name' => 'Смета'], $user);
            self::fail('Expected unresolved normative validation error.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('draft', $exception->errors());
        }

        $session->refresh();

        self::assertSame('review_required', $session->status);
        self::assertNull($session->applied_estimate_id);
    }

    private function seedNormativeWithPrice(): int
    {
        $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => '2026-05-07',
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
        $priceVersionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsbc',
            'version_key' => '2026-05-07',
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
        $collectionId = (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $versionId,
            'code' => 'gesn',
            'name' => 'ГЭСН',
            'norm_type' => 'gesn',
            'source_file' => 'ГЭСН.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = (int) DB::table('estimate_norm_sections')->insertGetId([
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
        $normId = (int) DB::table('estimate_norms')->insertGetId([
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

        DB::table('estimate_norm_resources')->insert([
            'estimate_norm_id' => $normId,
            'construction_resource_id' => null,
            'resource_code' => '01.1.01.01-0001',
            'resource_name' => 'Бетон тяжелый',
            'unit' => 'м3',
            'quantity' => 1.0,
            'resource_type' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
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

        return $normId;
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayloadWithCandidate(int $normId): array
    {
        return [
            'title' => 'Дом 150 м2',
            'local_estimates' => [[
                'key' => 'foundation',
                'title' => 'Фундамент',
                'scope_type' => 'foundation',
                'source_refs' => [],
                'assumptions' => [],
                'sections' => [[
                    'key' => 'foundation-section',
                    'title' => 'Фундамент',
                    'source_refs' => [],
                    'work_items' => [[
                        'key' => 'foundation-work-1',
                        'name' => 'Бетонирование фундаментов',
                        'description' => 'Устройство фундаментных конструкций',
                        'item_type' => 'priced_work',
                        'unit' => 'м3',
                        'quantity' => 2,
                        'quantity_basis' => 'по модели объекта',
                        'quantity_formula' => 'foundation.concrete',
                        'confidence' => 0.5,
                        'materials' => [],
                        'labor' => [],
                        'machinery' => [],
                        'other_resources' => [],
                        'work_cost' => 0,
                        'materials_cost' => 0,
                        'machinery_cost' => 0,
                        'labor_cost' => 0,
                        'total_cost' => 0,
                        'source_refs' => [],
                        'validation_flags' => [
                            'normative_candidate_only',
                            'requires_normative_review',
                            'missing_price',
                            'missing_resources',
                        ],
                        'normative_match' => ['status' => 'candidate'],
                        'normative_candidates' => [[
                            'norm_id' => $normId,
                            'code' => '01-01-001-01',
                            'name' => 'Бетонирование фундаментов',
                            'unit' => 'м3',
                            'confidence' => 0.65,
                            'resources_count' => 1,
                            'priced_resources_count' => 1,
                        ]],
                    ]],
                ]],
            ]],
            'quality_summary' => [
                'status' => 'review_required',
                'normative_items' => [
                    'accepted' => 0,
                    'candidate' => 1,
                    'rejected' => 0,
                    'not_found' => 0,
                    'requires_review' => 1,
                ],
            ],
            'totals' => [
                'total_cost' => 0,
                'work_items_count' => 1,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationExcelExportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use App\Enums\EstimatePositionItemType;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;

class EstimateGenerationNormativeOutputTest extends TestCase
{
    public function test_apply_persists_normative_metadata_to_work_and_resource_items(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = $this->createSession($organization, $project, $user);

        $estimate = app(EstimateDraftPersistenceService::class)->apply($session, ['name' => 'Смета'], $user);
        $work = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->where('item_type', EstimatePositionItemType::WORK->value)
            ->firstOrFail();
        $resource = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->where('parent_work_id', $work->id)
            ->firstOrFail();

        $this->assertSame('01-01-001-01', $work->normative_rate_code);
        $this->assertSame('01-01-001-01', $work->metadata['normative_match']['code']);
        $this->assertSame('fsnb_2022', $work->metadata['normative_dataset']['source_type']);
        $this->assertSame('01.1.01.01-0001', $resource->normative_rate_code);
        $this->assertSame('01.1.01.01-0001', $resource->metadata['normative_ref']['resource_code']);
        $this->assertSame(1.5, (float) $work->resources()->firstOrFail()->quantity_per_unit);
    }

    public function test_apply_uses_generated_short_name_when_payload_name_is_too_long(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = $this->createSession($organization, $project, $user);
        $session->forceFill([
            'input_payload' => [
                'description' => 'Хочу построить дом для семьи',
                'building_type' => 'Жилой дом',
                'area' => 180,
                'region' => 'Республика Татарстан',
            ],
            'draft_payload' => array_replace($session->draft_payload, [
                'title' => str_repeat('Длинное описание объекта ', 30),
            ]),
        ])->save();

        $estimate = app(EstimateDraftPersistenceService::class)->apply(
            $session->fresh(),
            ['name' => str_repeat('Хочу построить дом для семьи в Татарстане. ', 30)],
            $user
        );

        $this->assertLessThanOrEqual(255, mb_strlen($estimate->name));
        $this->assertSame('AI-смета • Жилой дом • 180 м² • Республика Татарстан', $estimate->name);
    }

    public function test_export_data_contains_normative_codes_and_metadata(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = $this->createSession($organization, $project, $user);
        $service = new EstimateGenerationExcelExportService(app(ExcelEstimateBuilder::class));

        $method = new \ReflectionMethod($service, 'prepareExportData');
        $method->setAccessible(true);
        $data = $method->invoke($service, $session, $session->draft_payload);
        $work = $data['sections'][1]['items'][0];
        $resource = $data['sections'][1]['items'][1];

        $this->assertSame('01-01-001-01', $work['normative_rate_code']);
        $this->assertSame('01-01-001-01', $work['metadata']['normative_match']['code']);
        $this->assertSame('01.1.01.01-0001', $resource['normative_rate_code']);
        $this->assertSame('01.1.01.01-0001', $resource['metadata']['normative_ref']['resource_code']);
        $this->assertSame('2026-05-07', $data['metadata']['normative_matching']['version_key']);
    }

    private function createSession(Organization $organization, Project $project, User $user): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'generated',
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'input_payload' => ['description' => 'Тестовая смета'],
            'draft_payload' => $this->draftPayload(),
            'problem_flags' => [],
        ]);
    }

    private function draftPayload(): array
    {
        return [
            'title' => 'Тестовая смета',
            'source_documents' => [],
            'normative_matching' => [
                'enabled' => true,
                'source_type' => 'fsnb_2022',
                'version_key' => '2026-05-07',
                'matched_work_items' => 1,
                'unmatched_work_items' => 0,
                'low_confidence_work_items' => 0,
            ],
            'traceability' => ['analysis' => []],
            'totals' => ['total_cost' => 3000],
            'local_estimates' => [[
                'key' => 'local-1',
                'title' => 'Фундаменты',
                'scope_type' => 'foundation',
                'source_refs' => [],
                'assumptions' => [],
                'totals' => ['total_cost' => 3000],
                'sections' => [[
                    'key' => 'section-1',
                    'title' => 'Фундаменты',
                    'construction_part' => 'foundation',
                    'source_refs' => [],
                    'section_totals' => ['total_cost' => 3000],
                    'work_items' => [[
                        'key' => 'work-1',
                        'name' => 'Бетонирование фундаментов',
                        'description' => 'Устройство фундаментных конструкций',
                        'work_category' => 'concrete',
                        'unit' => 'м3',
                        'quantity' => 2,
                        'quantity_basis' => 'manual',
                        'quantity_formula' => 'manual',
                        'work_cost' => 0,
                        'materials_cost' => 3000,
                        'machinery_cost' => 0,
                        'labor_cost' => 0,
                        'total_cost' => 3000,
                        'source_refs' => [],
                        'confidence' => 0.85,
                        'validation_flags' => [],
                        'normative_rate_code' => '01-01-001-01',
                        'normative_dataset' => [
                            'source_type' => 'fsnb_2022',
                            'version_key' => '2026-05-07',
                        ],
                        'normative_match' => [
                            'status' => 'matched',
                            'code' => '01-01-001-01',
                            'name' => 'Бетонирование фундаментов',
                            'confidence' => 0.9,
                        ],
                        'normative_candidates' => [[
                            'code' => '01-01-001-01',
                            'name' => 'Бетонирование фундаментов',
                            'confidence' => 0.9,
                        ]],
                        'materials' => [[
                            'key' => 'resource-1',
                            'name' => 'Бетон тяжелый',
                            'resource_type' => 'material',
                            'unit' => 'м3',
                            'quantity' => 3,
                            'quantity_per_unit' => 1.5,
                            'quantity_basis' => 'normative_resource',
                            'unit_price' => 1000,
                            'total_price' => 3000,
                            'source' => 'fsnb_2022:2026-05-07',
                            'confidence' => 0.9,
                            'normative_ref' => [
                                'norm_id' => 10,
                                'norm_code' => '01-01-001-01',
                                'resource_code' => '01.1.01.01-0001',
                                'resource_id' => null,
                                'price_id' => 20,
                                'price_source' => 'fsbc_2022_base',
                            ],
                        ]],
                        'labor' => [],
                        'machinery' => [],
                    ]],
                ]],
            ]],
        ];
    }
}

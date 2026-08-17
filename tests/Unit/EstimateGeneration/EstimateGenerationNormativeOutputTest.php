<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\FinalizedPackageDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use App\Enums\EstimatePositionItemType;
use App\Integrations\EstimateGeneration\EstimateGenerationExcelExportService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\EstimateGeneration\EstimateGenerationPostgresTestCase;

#[Group('postgres-contract')]
class EstimateGenerationNormativeOutputTest extends EstimateGenerationPostgresTestCase
{
    public function test_apply_persists_normative_metadata_to_work_and_resource_items(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = $this->createSession($organization, $project, $user);

        $estimateId = $this->writer()->createFromSession(
            $session,
            new ApplyGeneratedEstimateCommand(
                (int) $session->id,
                (int) $organization->id,
                (int) $project->id,
                (int) $session->state_version,
                'Смета',
            ),
        );
        $estimate = Estimate::query()->findOrFail($estimateId);
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

        $estimateId = $this->writer()->createFromSession(
            $session->fresh(),
            new ApplyGeneratedEstimateCommand(
                (int) $session->id,
                (int) $organization->id,
                (int) $project->id,
                (int) $session->state_version,
                str_repeat('Хочу построить дом для семьи в Татарстане. ', 30),
            ),
        );
        $estimate = Estimate::query()->findOrFail($estimateId);

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
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'estimate_review_required',
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'input_payload' => ['description' => 'Тестовая смета'],
            'draft_payload' => $this->draftPayload(),
            'problem_flags' => [],
        ]);
        $draft = $session->draft_payload;
        $regional = $this->regionalContext((int) $session->id);
        $snapshotHash = 'sha256:'.str_repeat('c', 64);
        $sourceVersion = 'sha256:'.str_repeat('d', 64);
        $scope = [
            'organization_id' => (int) $organization->id,
            'project_id' => (int) $project->id,
            'session_id' => (int) $session->id,
            'source_version' => $sourceVersion,
        ];
        $draft['input_snapshot_hash'] = $snapshotHash;
        $draft['scope_identity'] = $scope;
        $draft['building_model'] = [
            'scale_status' => 'confirmed',
            'evidence_ids' => [1],
            'metrics' => ['complete' => true],
            'cad_status' => 'not_required',
        ];
        $contentVersion = 'sha256:'.str_repeat('f', 64);
        $draft['quality_summary'] = [
            'status' => 'ready',
            'level' => 'passed',
            'content_version' => $contentVersion,
            'review_items' => [
                'source_version' => $contentVersion,
                'input_version' => $draft['source_input_version'],
                'classifier_version' => 2,
            ],
            'normative_items' => ['requires_review' => 0],
            'quantity_review_work_items' => 0,
            'not_calculated_work_items' => 0,
            'safe_norm_required_work_items' => 0,
            'duplicate_work_items' => 0,
        ];
        $workItem = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $workItem['quantity_evidence'] = [
            'amount' => '2',
            'unit' => 'м3',
            'evidence_ids' => ['evidence-1'],
            'snapshot_identity' => ['input_fingerprint' => $snapshotHash],
            'formula_inputs' => ['operands' => [$scope]],
            'formula_identity' => 'manual-confirmed-volume',
            'formula_version' => '1',
            'review_blockers' => [],
        ];
        $workItem['normative_match'] = array_replace($workItem['normative_match'], [
            'hard_gate_passed' => true,
            'unit' => 'м3',
            'dataset_version' => '2026-05-07',
        ]);
        $workItem['normative_retrieval'] = ['status' => 'matched', 'blocking_issues' => []];
        $workItem['pricing_blocker'] = null;
        $workItem['pricing_finalized_at'] = '2026-08-17T00:00:00+00:00';
        $workItem['price_snapshot'] = [
            'final_amount' => '3000.00',
            'region_id' => $regional['region_id'],
            'zone_id' => $regional['price_zone_id'],
            'period_id' => $regional['period_id'],
            'version_id' => $regional['version_id'],
            'source_reference' => 'sha256:'.str_repeat('e', 64),
            'captured_at' => $workItem['pricing_finalized_at'],
        ];
        unset($workItem);
        $draft['regional_context'] = [
            'region_id' => $regional['region_id'],
            'price_zone_id' => $regional['price_zone_id'],
            'period_id' => $regional['period_id'],
            'estimate_regional_price_version_id' => $regional['version_id'],
        ];
        $session->forceFill(['draft_payload' => $draft])->save();
        app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft(
            $session,
            $session->draft_payload,
            $session->draft_payload['source_input_version'],
        );
        $item = EstimateGenerationPackageItem::query()->whereHas(
            'package',
            static fn ($query) => $query->where('session_id', $session->id),
        )->firstOrFail();
        $item->forceFill([
            'unit_price' => '1500.000000',
            'direct_cost' => '3000.00',
            'overhead_cost' => '0.00',
            'profit_cost' => '0.00',
            'total_cost' => '3000.00',
            'price_source' => 'regional_catalog',
            'pricing_finalized_at' => '2026-08-17T00:00:00.000000Z',
            'price_snapshot' => [
                'final_amount' => '3000.00',
                'region_id' => $regional['region_id'],
                'zone_id' => $regional['price_zone_id'],
                'period_id' => $regional['period_id'],
                'version_id' => $regional['version_id'],
                'source_reference' => 'sha256:'.str_repeat('e', 64),
                'captured_at' => '2026-08-17T00:00:00.000000Z',
                'coefficients' => [
                    'pricing_formula_version' => 'project_resource:v3',
                    'resource_evidence' => [[
                        'norm_resource_id' => 100,
                        'resource_code' => '01.1.01.01-0001',
                        'resource_type' => 'material',
                        'norm_quantity' => '1.5',
                        'work_to_norm_factor' => '1',
                        'conversion_factor' => '1',
                        'resource_price_id' => 20,
                        'price_unit' => 'м3',
                        'base_price' => '1000',
                    ]],
                    'provenance' => ['resources' => [[
                        'norm_resource_id' => 100,
                        'resource_code' => '01.1.01.01-0001',
                        'resource_name' => 'Бетон тяжелый',
                        'resource_type' => 'material',
                        'price_id' => 20,
                        'regional_version' => ['version_key' => '2026-05-07'],
                    ]]],
                    'project_material_evidence' => [],
                ],
            ],
            'resources' => ['materials' => [[
                'name' => 'Бетон тяжелый',
                'unit' => 'м3',
                'normative_ref' => [
                    'norm_resource_id' => 100,
                    'resource_code' => '01.1.01.01-0001',
                    'price_id' => 20,
                ],
            ]], 'labor' => [], 'machinery' => [], 'other' => []],
        ])->save();
        $item->package()->update(['status' => 'ready_for_review']);
        $item->refresh();
        $this->assertSame('2.000000000000000000', (string) $item->quantity);
        $this->assertSame('priced_work', $item->item_type);
        $this->assertSame('м3', $item->unit);
        $this->assertSame('1500.000000', (string) $item->unit_price);
        $this->assertSame('3000.00', (string) $item->direct_cost);
        $this->assertSame('0.00', (string) $item->overhead_cost);
        $this->assertSame('0.00', (string) $item->profit_cost);
        $this->assertSame('3000.00', (string) $item->total_cost);
        $this->assertSame('regional_catalog', $item->price_source);
        $this->assertNotNull($item->pricing_finalized_at);
        $projected = app(BuildMostEstimateDraft::class)->build(
            app(FinalizedPackageDraftProjector::class)->project($session, $session->draft_payload),
        );
        $blocker = app(EstimateDraftPersistenceService::class)->findApplyBlocker($projected);
        $this->assertNull($blocker, json_encode([
            'blocker' => $blocker,
            'review_items' => $projected['stage6_review_items'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return $session;
    }

    private function writer(): LaravelGeneratedEstimateWriter
    {
        return new LaravelGeneratedEstimateWriter(
            app(EstimateDraftPersistenceService::class),
            app(GeneratedEstimateNumberAllocator::class),
        );
    }

    /** @return array{region_id: int, price_zone_id: int, period_id: int, version_id: int} */
    private function regionalContext(int $suffix): array
    {
        $now = now();
        $regionId = (int) DB::table('estimate_regions')->insertGetId([
            'code' => 'RU-RT-'.$suffix,
            'name' => 'Республика Татарстан',
            'fgiscs_subject_id' => 160000 + $suffix,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $priceZoneId = (int) DB::table('estimate_price_zones')->insertGetId([
            'estimate_region_id' => $regionId,
            'name' => 'Основная зона',
            'fgiscs_price_zone_id' => 1600000 + $suffix,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $periodId = (int) DB::table('estimate_price_periods')->insertGetId([
            'fgiscs_period_id' => 20260800 + $suffix,
            'name' => 'III квартал 2026',
            'year' => 2026,
            'quarter' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $versionId = (int) DB::table('estimate_regional_price_versions')->insertGetId([
            'source' => 'fgiscs',
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'version_key' => 'normative-output-'.$suffix,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('estimate_regional_price_versions')->where('id', $versionId)->update([
            'status' => 'active',
            'updated_at' => $now,
        ]);

        return [
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'version_id' => $versionId,
        ];
    }

    private function draftPayload(): array
    {
        return [
            'title' => 'Тестовая смета',
            'source_input_version' => 'sha256:'.str_repeat('a', 64),
            'catalog_identity' => ['status' => 'current', 'version' => '2026-05-07'],
            'technology_identity' => ['status' => 'current'],
            'rule_identity' => ['status' => 'current'],
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
                        'item_type' => 'priced_work',
                        'name' => 'Бетонирование ленточного фундамента бетоном B22.5',
                        'description' => 'Укладка бетона B22.5 в готовую опалубку ленточного фундамента',
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
                        'pricing_status' => 'calculated',
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
                            'resources_count' => 1,
                            'priced_resources_count' => 1,
                            'decision' => ['status' => 'accepted'],
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

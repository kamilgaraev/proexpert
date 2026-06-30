<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimateGenerationFeedbackRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EstimateGenerationNormativeSelectionLearningTest extends TestCase
{
    public function test_user_selected_normative_candidate_creates_positive_learning_example(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $normId = $this->seedNormative('01-01-006-01', 'Бетонирование фундаментной ленты B22.5', 'м3');
        $this->createPackageItem($session, 'foundation.concrete');
        $draftPayload = $this->draftPayload($normId);
        $draftPayload['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['quantity_feedback'] = [
            'status' => 'confirmed_by_user',
            'quantity' => 13.8,
            'unit' => $draftPayload['local_estimates'][0]['sections'][0]['work_items'][0]['unit'],
            'quantity_basis' => 'source-takeoff-checked',
        ];

        $session->forceFill([
            'draft_payload' => $draftPayload,
        ])->save();

        app(NormativeCandidateSelectionService::class)->select($session, 'foundation.concrete', $normId);

        $example = EstimateGenerationLearningExample::query()->firstOrFail();
        $currentPackageItem = EstimateGenerationPackageItem::query()
            ->where('key', 'foundation.concrete')
            ->whereHas('package', static fn ($query) => $query->where('session_id', $session->id))
            ->firstOrFail();

        $this->assertSame('user_selection', $example->source_type);
        $this->assertTrue($example->is_positive);
        $this->assertSame($session->id, $example->generation_session_id);
        $this->assertSame($currentPackageItem->id, $example->generation_package_item_id);
        $this->assertSame($normId, $example->estimate_norm_id);
        $this->assertSame('01-01-006-01', $example->norm_code);
        $this->assertSame('foundation.concrete', $example->context_payload['work_item_key']);
        $this->assertSame('offered_candidate', $example->context_payload['selection_source']);
        $this->assertCount(1, $example->context_payload['offered_candidates']);
        $this->assertSame(13.8, $example->context_payload['quantity_snapshot']['quantity']);
        $this->assertSame(
            $draftPayload['local_estimates'][0]['sections'][0]['work_items'][0]['unit'],
            $example->context_payload['quantity_snapshot']['unit']
        );
        $this->assertTrue($example->context_payload['quantity_snapshot']['confirmed_by_user']);
        $this->assertSame(
            'source-takeoff-checked',
            $example->context_payload['quantity_snapshot']['feedback']['quantity_basis']
        );
        $this->assertSame($user->id, $session->fresh()->user_id);
        $this->assertSame($project->id, $session->fresh()->project_id);
    }

    public function test_catalog_search_normative_selection_creates_positive_learning_example_without_offered_candidate(): void
    {
        [, , $session] = $this->makeSession();
        $normId = $this->seedNormative('01-01-006-01', 'Foundation concrete B22.5', 'm3');
        $this->createPackageItem($session, 'foundation.concrete');
        $draftPayload = $this->draftPayload($normId);
        $draftPayload['local_estimates'][0]['sections'][0]['work_items'][0]['normative_candidates'] = [];

        $session->forceFill([
            'draft_payload' => $draftPayload,
        ])->save();

        app(NormativeCandidateSelectionService::class)->select($session, 'foundation.concrete', $normId, true);

        $example = EstimateGenerationLearningExample::query()->firstOrFail();

        $this->assertSame('user_selection', $example->source_type);
        $this->assertTrue($example->is_positive);
        $this->assertSame($normId, $example->estimate_norm_id);
        $this->assertSame('01-01-006-01', $example->norm_code);
        $this->assertSame('foundation.concrete', $example->context_payload['work_item_key']);
        $this->assertSame('catalog_search', $example->context_payload['selection_source']);
        $this->assertSame($normId, $example->context_payload['selected_norm_id']);
        $this->assertSame('01-01-006-01', $example->context_payload['selected_normative_code']);
        $this->assertSame([], $example->context_payload['offered_candidates']);
    }

    public function test_normative_rejection_feedback_creates_negative_learning_example(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $normId = $this->seedNormative('01-01-006-01', 'Бетонирование фундаментной ленты B22.5', 'м3');
        $this->createPackageItem($session, 'foundation.concrete');
        $session->forceFill([
            'draft_payload' => $this->draftPayload($normId),
        ])->save();

        $request = EstimateGenerationFeedbackRequest::create('/feedback', 'POST', [
            'feedback_type' => 'normative_rejection',
            'work_item_key' => 'foundation.concrete',
            'payload' => [
                'norm_id' => $normId,
                'normative_code' => '01-01-006-01',
                'reason' => 'Не та работа',
            ],
            'comments' => 'Нужно выбрать другую норму',
        ]);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->setUserResolver(static fn (): User => $user);
        $request->validateResolved();

        app(EstimateGenerationController::class)->feedback($request, $project, $session);

        $example = EstimateGenerationLearningExample::query()->firstOrFail();
        $currentPackageItem = EstimateGenerationPackageItem::query()
            ->where('key', 'foundation.concrete')
            ->whereHas('package', static fn ($query) => $query->where('session_id', $session->id))
            ->firstOrFail();

        $this->assertSame('user_rejection', $example->source_type);
        $this->assertFalse($example->is_positive);
        $this->assertSame($session->id, $example->generation_session_id);
        $this->assertSame($currentPackageItem->id, $example->generation_package_item_id);
        $this->assertSame($normId, $example->estimate_norm_id);
        $this->assertSame('01-01-006-01', $example->context_payload['rejected_normative_code']);
        $this->assertSame('Не та работа', $example->context_payload['reason']);
    }

    public function test_catalog_search_normative_rejection_creates_negative_learning_example_without_offered_candidate(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $normId = $this->seedNormative('01-01-006-01', 'Foundation concrete B22.5', 'm3');
        $this->createPackageItem($session, 'foundation.concrete');
        $draftPayload = $this->draftPayload($normId);
        $draftPayload['local_estimates'][0]['sections'][0]['work_items'][0]['normative_candidates'] = [];
        $session->forceFill([
            'draft_payload' => $draftPayload,
        ])->save();

        $request = EstimateGenerationFeedbackRequest::create('/feedback', 'POST', [
            'feedback_type' => 'normative_rejection',
            'work_item_key' => 'foundation.concrete',
            'payload' => [
                'selection_source' => 'catalog_search',
                'norm_id' => $normId,
                'normative_code' => '01-01-006-01',
                'reason' => 'wrong catalog match',
            ],
            'comments' => 'Manual search result is not suitable.',
        ]);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->setUserResolver(static fn (): User => $user);
        $request->validateResolved();

        $response = app(EstimateGenerationController::class)->feedback($request, $project, $session);

        $this->assertSame(200, $response->getStatusCode());

        $example = EstimateGenerationLearningExample::query()->firstOrFail();
        $updatedWorkItem = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];

        $this->assertSame('user_rejection', $example->source_type);
        $this->assertFalse($example->is_positive);
        $this->assertSame($normId, $example->estimate_norm_id);
        $this->assertSame('01-01-006-01', $example->context_payload['rejected_normative_code']);
        $this->assertSame('catalog_search', $example->context_payload['selection_source']);
        $this->assertSame('catalog_search', $updatedWorkItem['metadata']['normative_feedback']['selection_source']);
        $this->assertTrue($updatedWorkItem['normative_candidates'][0]['rejected_by_user']);
        $this->assertSame('catalog_search', $updatedWorkItem['normative_candidates'][0]['selection_source']);
    }

    public function test_normative_confirmation_feedback_creates_positive_learning_example(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $normId = $this->seedNormative('12-01-013-01', 'Roof insulation', 'm2');
        $this->createPackageItem($session, 'roof.insulation');
        $session->forceFill([
            'draft_payload' => $this->reviewPricedDraftPayload($normId),
        ])->save();

        $request = EstimateGenerationFeedbackRequest::create('/feedback', 'POST', [
            'feedback_type' => 'normative_confirmation',
            'work_item_key' => 'roof.insulation',
            'payload' => [
                'norm_id' => $normId,
                'normative_code' => '12-01-013-01',
            ],
            'comments' => 'Checked by estimator.',
        ]);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->setUserResolver(static fn (): User => $user);
        $request->validateResolved();

        app(EstimateGenerationController::class)->feedback($request, $project, $session);

        $example = EstimateGenerationLearningExample::query()->firstOrFail();
        $currentPackageItem = EstimateGenerationPackageItem::query()
            ->where('key', 'roof.insulation')
            ->whereHas('package', static fn ($query) => $query->where('session_id', $session->id))
            ->firstOrFail();

        $this->assertSame('manual_review_choice', $example->source_type);
        $this->assertTrue($example->is_positive);
        $this->assertSame($session->id, $example->generation_session_id);
        $this->assertSame($currentPackageItem->id, $example->generation_package_item_id);
        $this->assertSame($normId, $example->estimate_norm_id);
        $this->assertSame('12-01-013-01', $example->norm_code);
        $this->assertSame('confirmed_by_user', $example->decision_status);
        $this->assertSame('roof.insulation', $example->context_payload['work_item_key']);
        $this->assertSame('Checked by estimator.', $example->context_payload['comments']);
    }

    public function test_quantity_confirmation_feedback_creates_manual_quantity_learning_example(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $this->createPackageItem($session, 'rough.walls');
        $session->forceFill([
            'draft_payload' => $this->quantityReviewDraftPayload(),
        ])->save();

        $request = EstimateGenerationFeedbackRequest::create('/feedback', 'POST', [
            'feedback_type' => 'quantity_confirmation',
            'work_item_key' => 'rough.walls',
            'payload' => [
                'quantity' => 218.25,
                'unit' => 'м2',
                'quantity_basis' => 'Проверено по планировке, площадь стен 218,25 м2.',
            ],
            'comments' => 'Проверил площадь стен по планировке.',
        ]);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->setUserResolver(static fn (): User => $user);
        $request->validateResolved();

        $response = app(EstimateGenerationController::class)->feedback($request, $project, $session);

        $this->assertSame(200, $response->getStatusCode());

        $example = EstimateGenerationLearningExample::query()->firstOrFail();
        $updatedWorkItem = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];

        $this->assertSame('manual_quantity_confirmation', $example->source_type);
        $this->assertTrue($example->is_positive);
        $this->assertSame('quantity:rough.walls', $example->norm_code);
        $this->assertNull($example->estimate_norm_id);
        $this->assertSame($session->id, $example->generation_session_id);
        $this->assertSame('rough.walls', $example->context_payload['work_item_key']);
        $this->assertSame('rough.walls', $example->context_payload['quantity_key']);
        $this->assertSame(218.25, $example->context_payload['quantity_snapshot']['quantity']);
        $this->assertSame('м2', $example->context_payload['quantity_snapshot']['unit']);
        $this->assertTrue($example->context_payload['quantity_snapshot']['confirmed_by_user']);
        $this->assertSame('wall_area_from_floor_plan', $example->context_payload['calculation_basis']);
        $this->assertSame('Проверил площадь стен по планировке.', $example->context_payload['comments']);
        $this->assertSame('confirmed_by_user', $updatedWorkItem['metadata']['quantity_feedback']['status']);
        $this->assertSame(218.25, $updatedWorkItem['metadata']['quantity_feedback']['quantity']);
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function makeSession(): array
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
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [],
            'problem_flags' => [],
        ]);

        return [$user, $project, $session];
    }

    private function createPackageItem(EstimateGenerationSession $session, string $key): EstimateGenerationPackageItem
    {
        $package = EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'key' => 'foundation',
            'title' => 'Фундамент',
            'scope_type' => 'foundation',
            'status' => 'review_required',
            'sort_order' => 100,
        ]);

        return EstimateGenerationPackageItem::query()->create([
            'package_id' => $package->id,
            'key' => $key,
            'item_type' => 'priced_work',
            'name' => 'Бетонирование фундаментной ленты B22.5',
            'unit' => 'м3',
            'quantity' => 13.8,
            'sort_order' => 100,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(int $normId): array
    {
        return [
            'local_estimates' => [[
                'key' => 'foundation',
                'title' => 'Фундамент',
                'scope_type' => 'foundation',
                'source_refs' => [['type' => 'test', 'id' => 'source-1']],
                'sections' => [[
                    'key' => 'foundation.main',
                    'title' => 'Фундамент',
                    'source_refs' => [['type' => 'test', 'id' => 'source-1']],
                    'work_items' => [[
                        'key' => 'foundation.concrete',
                        'name' => 'Бетонирование фундаментной ленты B22.5',
                        'normative_search_text' => 'Бетонирование фундаментной ленты B22.5',
                        'unit' => 'м3',
                        'quantity' => 13.8,
                        'unit_price' => 0,
                        'total_cost' => 0,
                        'normative_candidates' => [[
                            'norm_id' => $normId,
                            'code' => '01-01-006-01',
                            'name' => 'Бетонирование фундаментной ленты B22.5',
                            'unit' => 'м3',
                        ]],
                    ]],
                ]],
            ]],
            'quality_summary' => [
                'normative_items' => [
                    'requires_review' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPricedDraftPayload(int $normId): array
    {
        return [
            'local_estimates' => [[
                'key' => 'roof',
                'title' => 'Roof',
                'scope_type' => 'roof',
                'source_refs' => [['type' => 'test', 'id' => 'source-2']],
                'sections' => [[
                    'key' => 'roof.main',
                    'title' => 'Roof',
                    'source_refs' => [['type' => 'test', 'id' => 'source-2']],
                    'work_items' => [[
                        'key' => 'roof.insulation',
                        'name' => 'Roof insulation',
                        'normative_search_text' => 'Roof insulation',
                        'unit' => 'm2',
                        'quantity' => 100.0,
                        'quantity_basis' => 'Drawing A101',
                        'unit_price' => 100.0,
                        'total_cost' => 10000.0,
                        'materials' => [['total_price' => 10000.0]],
                        'labor' => [],
                        'machinery' => [],
                        'other_resources' => [],
                        'pricing_status' => 'calculated_review_required',
                        'normative_rate_code' => '12-01-013-01',
                        'validation_flags' => ['requires_normative_review', 'safe_normative_analog'],
                        'normative_match' => [
                            'status' => 'matched',
                            'selected_by_user' => false,
                            'norm_id' => $normId,
                            'code' => '12-01-013-01',
                            'name' => 'Roof insulation',
                            'decision' => [
                                'status' => 'review_priced',
                                'can_use_for_pricing' => true,
                                'warnings' => ['requires_normative_review', 'safe_normative_analog'],
                            ],
                        ],
                    ]],
                ]],
            ]],
            'quality_summary' => [
                'normative_items' => [
                    'requires_review' => 1,
                    'review_priced' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quantityReviewDraftPayload(): array
    {
        return [
            'local_estimates' => [[
                'key' => 'rough',
                'title' => 'Черновые работы',
                'scope_type' => 'rough',
                'source_refs' => [['type' => 'document', 'filename' => 'plan.pdf', 'page_number' => 1]],
                'sections' => [[
                    'key' => 'rough.main',
                    'title' => 'Стены',
                    'source_refs' => [['type' => 'document', 'filename' => 'plan.pdf', 'page_number' => 1]],
                    'work_items' => [[
                        'key' => 'rough.walls',
                        'name' => 'Площадь стен',
                        'item_type' => 'quantity_review',
                        'unit' => 'м2',
                        'quantity' => 220.5,
                        'quantity_formula' => '(46.52 + 9.99) * 2.7 - проемы',
                        'quantity_basis' => 'Площадь стен извлечена из планировки.',
                        'pricing_status' => 'not_applicable',
                        'pricing_blocker' => 'quantity_review_required',
                        'materials' => [],
                        'labor' => [],
                        'machinery' => [],
                        'other_resources' => [],
                        'total_cost' => 0.0,
                        'validation_flags' => ['quantity_review_required'],
                        'metadata' => [
                            'quantity_key' => 'rough.walls',
                            'display_role' => 'quantity_review',
                            'calculation_basis' => 'wall_area_from_floor_plan',
                        ],
                        'source_refs' => [['type' => 'document', 'filename' => 'plan.pdf', 'page_number' => 1]],
                    ]],
                ]],
            ]],
            'quality_summary' => [
                'quantity_review_work_items' => 1,
                'normative_items' => [
                    'requires_review' => 0,
                ],
            ],
        ];
    }

    private function seedNormative(string $code, string $name, string $unit): int
    {
        $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => 'test-' . Str::uuid(),
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
            'code' => '01',
            'name' => 'Строительные работы',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => '01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'section_id' => $sectionId,
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'section_code' => '01',
            'section_name' => 'Строительные работы',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

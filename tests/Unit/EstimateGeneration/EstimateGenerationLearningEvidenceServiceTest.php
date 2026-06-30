<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EstimateGenerationLearningEvidenceServiceTest extends TestCase
{
    private static int $datasetVersionSequence = 0;

    public function test_positive_examples_for_similar_work_boost_same_norm_code(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $norm = $this->norm('01-01-001-01', 'Бетонирование фундаментной ленты', 'м3');

        $this->learningExample($organization->id, [
            'project_id' => $project->id,
            'estimate_norm_id' => $norm->id,
            'norm_code' => $norm->code,
            'work_name' => 'Бетонирование фундаментной ленты B22.5',
            'work_unit' => 'м3',
            'normative_unit' => 'м3',
            'work_intent' => ['scope' => 'foundation', 'action' => 'concreting', 'system' => null],
            'is_positive' => true,
            'source_type' => 'imported_estimate',
        ]);

        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(
            collect([$norm]),
            ['name' => 'Бетонирование фундаментной ленты B22.5', 'unit' => 'м3'],
            ['organization_id' => $organization->id, 'project_id' => $project->id, 'scope_type' => 'foundation']
        );

        $this->assertGreaterThan(0, $summary[$norm->id]['learning_score']);
        $this->assertSame(1, $summary[$norm->id]['learning_positive_count']);
        $this->assertSame(0, $summary[$norm->id]['learning_negative_count']);
        $this->assertSame('imported_estimate', $summary[$norm->id]['learning_sources'][0]['source_type']);
    }

    public function test_manual_review_choices_are_weighted_above_imported_examples(): void
    {
        $organization = Organization::factory()->create();
        $importedNorm = $this->norm('01-01-001-01', 'Imported foundation concrete norm', 'm3');
        $manualNorm = $this->norm('01-01-002-01', 'Estimator confirmed foundation concrete norm', 'm3');
        $workPayload = [
            'work_name' => 'Foundation strip concrete B22.5',
            'work_unit' => 'm3',
            'normative_unit' => 'm3',
            'work_intent' => ['scope' => 'foundation', 'action' => 'concreting', 'system' => null],
            'is_positive' => true,
            'source_quality_score' => 1.0,
        ];

        $this->learningExample($organization->id, [
            ...$workPayload,
            'estimate_norm_id' => $importedNorm->id,
            'norm_code' => $importedNorm->code,
            'source_type' => 'imported_estimate',
        ]);
        $this->learningExample($organization->id, [
            ...$workPayload,
            'estimate_norm_id' => $manualNorm->id,
            'norm_code' => $manualNorm->code,
            'source_type' => 'manual_review_choice',
            'decision_status' => 'confirmed_by_user',
        ]);

        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(
            collect([$importedNorm, $manualNorm]),
            ['name' => 'Foundation strip concrete B22.5', 'unit' => 'm3'],
            ['organization_id' => $organization->id, 'scope_type' => 'foundation']
        );

        $this->assertGreaterThan(
            $summary[$importedNorm->id]['learning_score'],
            $summary[$manualNorm->id]['learning_score']
        );
        $this->assertSame('manual_review_choice', $summary[$manualNorm->id]['learning_sources'][0]['source_type']);
    }

    public function test_negative_examples_for_same_work_and_norm_penalize_candidate(): void
    {
        $organization = Organization::factory()->create();
        $norm = $this->norm('01-01-006-01', 'Планировка площадей', 'м2');

        $this->learningExample($organization->id, [
            'estimate_norm_id' => $norm->id,
            'norm_code' => $norm->code,
            'work_name' => 'Опалубка ленточного фундамента',
            'work_unit' => 'м2',
            'normative_unit' => 'м2',
            'work_intent' => ['scope' => 'foundation', 'action' => 'formwork', 'system' => null],
            'is_positive' => false,
            'source_type' => 'user_rejection',
            'decision_status' => 'rejected_by_user',
            'source_quality_score' => 1.0,
        ]);

        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(
            collect([$norm]),
            ['name' => 'Опалубка ленточного фундамента', 'unit' => 'м2'],
            ['organization_id' => $organization->id, 'scope_type' => 'foundation']
        );

        $this->assertLessThan(0, $summary[$norm->id]['learning_score']);
        $this->assertSame(0, $summary[$norm->id]['learning_positive_count']);
        $this->assertSame(1, $summary[$norm->id]['learning_negative_count']);
    }

    public function test_project_examples_are_ranked_before_organization_wide_memory(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $norm = $this->norm('12-01-013-01', 'Утепление покрытий кровли', 'м2');

        $this->learningExample($organization->id, [
            'project_id' => null,
            'estimate_norm_id' => $norm->id,
            'norm_code' => $norm->code,
            'work_name' => 'Утепление кровли',
            'work_unit' => 'м2',
            'normative_unit' => 'м2',
            'work_intent' => ['scope' => 'roof', 'action' => 'insulation', 'system' => null],
            'source_type' => 'imported_estimate',
        ]);
        $projectExample = $this->learningExample($organization->id, [
            'project_id' => $project->id,
            'estimate_norm_id' => $norm->id,
            'norm_code' => $norm->code,
            'work_name' => 'Утепление кровли 200 мм',
            'work_unit' => 'м2',
            'normative_unit' => 'м2',
            'work_intent' => ['scope' => 'roof', 'action' => 'insulation', 'system' => null],
            'source_type' => 'user_selection',
            'source_quality_score' => 1.0,
        ]);

        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(
            collect([$norm]),
            ['name' => 'Утепление кровли 200 мм', 'unit' => 'м2'],
            ['organization_id' => $organization->id, 'project_id' => $project->id, 'scope_type' => 'roof']
        );

        $this->assertSame($projectExample->id, $summary[$norm->id]['learning_sources'][0]['example_id']);
    }

    public function test_learning_evidence_never_bypasses_unit_gate(): void
    {
        $organization = Organization::factory()->create();
        $norm = $this->norm('01-01-001-01', 'Бетонирование фундаментов', 'м3');

        $this->learningExample($organization->id, [
            'estimate_norm_id' => $norm->id,
            'norm_code' => $norm->code,
            'work_name' => 'Опалубка ленточного фундамента',
            'work_unit' => 'м2',
            'normative_unit' => 'м3',
            'work_intent' => ['scope' => 'foundation', 'action' => 'formwork', 'system' => null],
            'is_positive' => true,
            'source_type' => 'user_selection',
            'source_quality_score' => 1.0,
        ]);

        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(
            collect([$norm]),
            ['name' => 'Опалубка ленточного фундамента', 'unit' => 'м2'],
            ['organization_id' => $organization->id, 'scope_type' => 'foundation']
        );

        $this->assertSame(0.0, $summary[$norm->id]['learning_score']);
        $this->assertSame(0, $summary[$norm->id]['learning_positive_count']);
    }

    public function test_untrusted_generated_examples_are_not_scored(): void
    {
        $organization = Organization::factory()->create();
        $norm = $this->norm('01-01-001-01', 'Бетонирование фундаментной ленты', 'м3');

        $this->learningExample($organization->id, [
            'estimate_norm_id' => $norm->id,
            'norm_code' => $norm->code,
            'work_name' => 'Бетонирование фундаментной ленты B22.5',
            'work_unit' => 'м3',
            'normative_unit' => 'м3',
            'work_intent' => ['scope' => 'foundation', 'action' => 'concreting', 'system' => null],
            'is_positive' => true,
            'source_type' => 'ai_generated_estimate',
            'source_quality_score' => 1.0,
        ]);

        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(
            collect([$norm]),
            ['name' => 'Бетонирование фундаментной ленты B22.5', 'unit' => 'м3'],
            ['organization_id' => $organization->id, 'scope_type' => 'foundation']
        );

        $this->assertSame(0.0, $summary[$norm->id]['learning_score']);
        $this->assertSame(0, $summary[$norm->id]['learning_positive_count']);
        $this->assertSame([], $summary[$norm->id]['learning_sources']);
    }

    private function norm(string $code, string $name, string $unit): EstimateNorm
    {
        $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => sprintf('2026-05-30-%04d', ++self::$datasetVersionSequence),
            'bucket' => 'test',
            'prefix' => 'test',
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
            'source_file' => 'test.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = (int) DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collectionId,
            'parent_id' => null,
            'code' => substr($code, 0, 2),
            'name' => 'Раздел',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => substr($code, 0, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $normId = (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'section_id' => $sectionId,
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'section_code' => substr($code, 0, 8),
            'section_name' => $name,
            'work_composition' => json_encode(['Состав работ'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return EstimateNorm::query()->findOrFail($normId);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function learningExample(int $organizationId, array $overrides): EstimateGenerationLearningExample
    {
        return EstimateGenerationLearningExample::query()->create([
            'organization_id' => $organizationId,
            'project_id' => null,
            'source_type' => 'imported_estimate',
            'source_entity_type' => 'estimate_item',
            'source_entity_id' => random_int(1000, 999999),
            'work_name' => 'Работа',
            'work_unit' => 'м3',
            'work_quantity' => 1,
            'work_intent' => ['scope' => 'foundation', 'action' => 'concreting', 'system' => null],
            'estimate_norm_id' => null,
            'norm_code' => '01-01-001-01',
            'normative_name' => 'Норма',
            'normative_unit' => 'м3',
            'decision_status' => 'imported_selected',
            'confidence' => 0.9,
            'is_positive' => true,
            'source_quality_score' => 0.85,
            'context_payload' => [],
            'source_refs' => [],
            'quality_flags' => ['unit_compatible'],
            'accepted_at' => now(),
            ...$overrides,
        ]);
    }
}

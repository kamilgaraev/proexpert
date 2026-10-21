<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

#[Group('postgres-contract')]
final class EstimateGenerationLearningEvidenceServiceTest extends EstimateGenerationCanonicalPostgresTestCase
{
    private static int $datasetVersionSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertInstanceOf(PostgresConnection::class, DB::connection());
        self::assertStringEndsWith('_testing', (string) DB::getDatabaseName());
    }

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

    #[DataProvider('signedSignals')]
    public function test_recorder_persists_current_signed_evidence_and_repeated_feedback_is_idempotent(bool $positive): void
    {
        $organization = Organization::factory()->create();
        $norm = $this->norm('01-01-001-01', 'Бетонирование фундамента', 'м3');
        $example = $this->learningExample($organization->id, [
            'estimate_norm_id' => $norm->id, 'norm_code' => $norm->code,
            'work_name' => 'Бетонирование фундамента', 'is_positive' => $positive,
            'source_type' => $positive ? 'user_selection' : 'user_rejection',
            'decision_status' => $positive ? 'confirmed_by_user' : 'rejected_by_user',
        ]);
        $attributes = $example->only($example->getFillable());
        self::assertSame(0, app(EstimateGenerationLearningRecorder::class)->record($attributes));
        self::assertSame(1, EstimateGenerationLearningExample::query()->where('source_entity_id', $example->source_entity_id)->count());
        self::assertSame((int) $norm->collection->dataset_version_id, (int) $example->normative_dataset_version_id);
        self::assertSame($positive, $example->fresh()->is_positive);

        $summary = $this->summary($norm, $organization->id);
        self::assertSame($positive ? 1 : 0, $summary['learning_positive_count']);
        self::assertSame($positive ? 0 : 1, $summary['learning_negative_count']);
        self::assertSame($example->id, $summary['learning_sources'][0]['example_id']);
        self::assertSame($positive, $summary['learning_sources'][0]['is_positive']);
        $positive ? self::assertGreaterThan(0, $summary['learning_score']) : self::assertLessThan(0, $summary['learning_score']);
    }

    #[DataProvider('signedSignals')]
    public function test_business_recorder_preserves_authoritative_version_and_units_for_reader_reuse(bool $positive): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $norm = $this->norm('01-01-001-01', 'Бетонирование фундамента', 'м3');
        $work = ['key' => 'foundation-concrete', 'name' => 'Бетонирование фундамента', 'unit' => 'м3', 'quantity' => 12];
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'status' => 'draft', 'input_payload' => [],
            'draft_payload' => ['local_estimates' => [['sections' => [['key' => 'foundation', 'title' => 'Фундамент', 'work_items' => [$work]]]]]],
        ]);
        $recorder = app(EstimateGenerationLearningRecorder::class);
        if ($positive) {
            self::assertSame(1, $recorder->recordUserSelection($session, $work, $work, $norm->id, ['scope_type' => 'foundation']));
            self::assertSame(0, $recorder->recordUserSelection($session, $work, $work, $norm->id, ['scope_type' => 'foundation']));
        } else {
            $feedback = EstimateGenerationFeedback::query()->create([
                'session_id' => $session->id, 'user_id' => $user->id, 'feedback_type' => 'normative_rejection',
                'work_item_key' => $work['key'], 'payload' => ['norm_id' => $norm->id],
            ]);
            self::assertSame(1, $recorder->recordFeedbackDecision($session, $feedback));
            self::assertSame(0, $recorder->recordFeedbackDecision($session, $feedback));
        }
        $example = EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->sole();
        self::assertSame((int) $norm->collection->dataset_version_id, (int) $example->normative_dataset_version_id);
        self::assertSame('м3', $example->work_unit);
        self::assertSame('м3', $example->normative_unit);
        self::assertSame($positive, $example->is_positive);

        $summary = $this->summary($norm, $organization->id, $project->id);
        self::assertSame($positive ? 1 : 0, $summary['learning_positive_count']);
        self::assertSame($positive ? 0 : 1, $summary['learning_negative_count']);
        self::assertSame($example->id, $summary['learning_sources'][0]['example_id']);
        self::assertSame($positive ? 'user_selection' : 'user_rejection', $summary['learning_sources'][0]['source_type']);
    }

    #[DataProvider('ineligibleSignedEvidence')]
    public function test_ineligible_persisted_evidence_has_no_score_counts_or_trusted_sources(bool $positive, array $overrides): void
    {
        $organization = Organization::factory()->create();
        $norm = $this->norm('01-01-001-01', 'Бетонирование фундамента', 'м3');
        $example = $this->learningExample($organization->id, [
            'estimate_norm_id' => $norm->id, 'norm_code' => $norm->code,
            'work_name' => 'Бетонирование фундамента', 'is_positive' => $positive,
            'source_type' => $positive ? 'user_selection' : 'user_rejection',
            ...$overrides,
        ]);

        $this->assertNoInfluence($this->summary($norm, $organization->id));
        self::assertTrue($example->fresh()->exists);
        self::assertSame($positive, $example->fresh()->is_positive);
    }

    #[DataProvider('signedSignals')]
    public function test_a_previous_dataset_signal_does_not_influence_the_same_code_in_a_new_dataset(bool $positive): void
    {
        $organization = Organization::factory()->create();
        $old = $this->norm('01-01-001-01', 'Бетонирование фундамента', 'м3');
        $current = $this->norm('01-01-001-01', 'Бетонирование фундамента', 'м3');
        $this->learningExample($organization->id, [
            'estimate_norm_id' => $old->id, 'norm_code' => $old->code,
            'work_name' => 'Бетонирование фундамента', 'is_positive' => $positive,
            'source_type' => $positive ? 'user_selection' : 'user_rejection',
        ]);

        self::assertNotSame($old->collection->dataset_version_id, $current->collection->dataset_version_id);
        $this->assertNoInfluence($this->summary($current, $organization->id));
        $sameVersion = $this->summary($old, $organization->id);
        self::assertSame($positive ? 1 : 0, $sameVersion['learning_positive_count']);
        self::assertSame($positive ? 0 : 1, $sameVersion['learning_negative_count']);
    }

    #[DataProvider('signedSignals')]
    public function test_project_local_signal_requires_its_project_context_and_never_crosses_organizations(bool $positive): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $norm = $this->norm('01-01-001-01', 'Бетонирование фундамента', 'м3');
        $this->learningExample($organization->id, [
            'project_id' => $project->id, 'estimate_norm_id' => $norm->id, 'norm_code' => $norm->code,
            'work_name' => 'Бетонирование фундамента', 'is_positive' => $positive,
            'source_type' => $positive ? 'user_selection' : 'user_rejection',
        ]);

        $this->assertNoInfluence($this->summary($norm, $organization->id));
        $this->assertNoInfluence($this->summary($norm, $organization->id, $otherProject->id));
        $this->assertNoInfluence($this->summary($norm, $otherOrganization->id));
        $matched = $this->summary($norm, $organization->id, $project->id);
        self::assertSame($positive ? 1 : 0, $matched['learning_positive_count']);
        self::assertSame($positive ? 0 : 1, $matched['learning_negative_count']);
    }

    public static function signedSignals(): iterable
    {
        yield 'positive' => [true];
        yield 'negative' => [false];
    }

    public static function ineligibleSignedEvidence(): iterable
    {
        foreach ([true, false] as $positive) {
            foreach ([
                'missing_version' => ['normative_dataset_version_id' => null],
                'unverified_unit' => ['quality_flags' => ['unit_unverified']],
                'missing_work_unit' => ['work_unit' => null],
                'unknown_work_unit' => ['work_unit' => 'неизвестная единица'],
                'missing_normative_unit' => ['normative_unit' => null],
                'incompatible_normative_unit' => ['normative_unit' => 'м2'],
                'withdrawn_from_index' => ['quality_flags' => ['do_not_index']],
            ] as $case => $overrides) {
                yield ($positive ? 'positive_' : 'negative_').$case => [$positive, $overrides];
            }
        }
    }

    private function summary(EstimateNorm $norm, int $organizationId, ?int $projectId = null): array
    {
        return app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(
            collect([$norm]), ['name' => 'Бетонирование фундамента', 'unit' => 'м3'],
            ['organization_id' => $organizationId, 'project_id' => $projectId, 'scope_type' => 'foundation']
        )[$norm->id];
    }

    private function assertNoInfluence(array $summary): void
    {
        self::assertSame(0.0, $summary['learning_score']);
        self::assertSame(0, $summary['learning_positive_count']);
        self::assertSame(0, $summary['learning_negative_count']);
        self::assertSame([], $summary['learning_sources']);
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
     * @param  array<string, mixed>  $overrides
     */
    private function learningExample(int $organizationId, array $overrides): EstimateGenerationLearningExample
    {
        $norm = isset($overrides['estimate_norm_id']) ? EstimateNorm::query()->with('collection')->find($overrides['estimate_norm_id']) : null;
        $attributes = [
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
            'normative_dataset_version_id' => $norm?->collection?->dataset_version_id,
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
        ];
        self::assertSame(1, app(EstimateGenerationLearningRecorder::class)->record($attributes));

        return EstimateGenerationLearningExample::query()->where('source_entity_id', $attributes['source_entity_id'])->firstOrFail();
    }
}

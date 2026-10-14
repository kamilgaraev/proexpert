<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\RecordedUniversalPipelineAdapter;

final class RecordedLifecyclePipelinePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_reconstruction_keeps_demolition_protection_and_waste_in_the_partial_composition_without_inventing_numbers(): void
    {
        $this->assertPartialLifecycle('text-reconstruction-v1', ['demolition-description', 'protection-description', 'waste-description', 'painting-description'],
            ['demolition', 'existing_structures_protection', 'waste_removal']);
    }

    public function test_industrial_reconstruction_preserves_equipment_and_commissioning_but_cannot_pass_the_unverified_profile(): void
    {
        $this->assertPartialLifecycle('text-industrial-reconstruction-v1', ['equipment.purchase', 'equipment-delivery-description', 'equipment.install',
            'equipment-commissioning-description', 'automation-testing-description'],
            ['equipment_specification', 'equipment_purchase', 'delivery', 'installation', 'commissioning', 'automation_test_program',
                'industrial_specification', 'technology_requirements', 'industry_work_checklist', 'industrial_profile_validation']);
    }

    private function assertPartialLifecycle(string $name, array $workKeys, array $requirements): void
    {
        $root = base_path('tests/Fixtures/EstimateGeneration/universal-replay');
        $fixturePath = $root.'/'.$name.'.json';
        $input = $name.'-input.txt';
        $adapter = new RecordedUniversalPipelineAdapter($this->app, new LocalBenchmarkObjectReader($root), $fixturePath, hash_file('sha256', $fixturePath));
        $result = $adapter->run(new BenchmarkPredictionCaseData($name, BenchmarkDatasetType::Regression, BenchmarkSourceType::TextDescription,
            $input, hash_file('sha256', $root.'/'.$input), ['reconstruction', 'incomplete_project'], []), 300000);
        self::assertSame('success', $result->status);
        $saved = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id'])->draft_payload;
        $builder = app(BuildMostEstimateDraft::class);
        self::assertTrue($builder->verifyArtifact($saved), 'The persisted result after the real ValidateDraft audit must retain its final business signature.');
        $tampered = $saved;
        $tampered['evaluation_result']['result_class'] = 'verified_scope_estimate';
        self::assertFalse($builder->verifyArtifact($tampered), 'Changed business content cannot retain the accepted signature.');
        $evaluation = $adapter->lastRun['evaluation'];
        self::assertFalse($evaluation['accuracy_calibrated']);
        self::assertFalse($evaluation['can_confirm_for_apply']);
        self::assertSame('scenario_estimate', $evaluation['result_class']);
        $positions = $evaluation['scenarios'][0]['positions'];
        foreach ($workKeys as $workKey) {
            self::assertContains($workKey, array_column($positions, 'work_key'));
        }
        foreach ($positions as $position) {
            self::assertNull($position['quantity']);
            self::assertNull($position['unit_price']);
            self::assertNull($position['total_cost']);
        }
        $missing = array_column($evaluation['missing_requirements'], 'requirement');
        foreach ($requirements as $requirement) {
            self::assertContains($requirement, $missing);
        }
        self::assertNotEmpty($evaluation['priority_questions']);
        self::assertLessThanOrEqual(3, count($evaluation['priority_questions']));
        $stages = array_column(ProcessingStage::cases(), 'value');
        sort($stages);
        self::assertSame($stages, $adapter->lastRun['completed_stages']);
        self::assertSame(2, $adapter->lastRun['provider_requests']);
        self::assertSame(2, $adapter->lastRun['requests_after_replay']);
        self::assertSame(2, $adapter->lastRun['usage_after_replay']);
        self::assertSame(0, $adapter->lastRun['consumed_units_minor']);
        self::assertSame(1000000, $adapter->lastRun['available_minor']);
        self::assertSame(1, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $adapter->lastRun['session_id'])->count());
    }
}

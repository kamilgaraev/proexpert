<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Training;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingDatasetTrustPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrainingDatasetTrustPolicyTest extends TestCase
{
    #[Test]
    public function acceptance_examples_can_only_be_used_for_benchmarking(): void
    {
        $dataset = new EstimateGenerationTrainingDataset([
            'dataset_type' => 'acceptance',
            'status' => 'approved',
        ]);
        $policy = new TrainingDatasetTrustPolicy;

        self::assertFalse($policy->canTrain($dataset));
        self::assertFalse($policy->canTuneRules($dataset));
        self::assertTrue($policy->canBenchmark($dataset));
    }

    #[Test]
    public function regression_examples_are_excluded_from_training_but_allowed_for_benchmarking(): void
    {
        $dataset = new EstimateGenerationTrainingDataset([
            'dataset_type' => 'regression',
            'status' => 'approved',
        ]);
        $policy = new TrainingDatasetTrustPolicy;

        self::assertFalse($policy->canTrain($dataset));
        self::assertFalse($policy->canTuneRules($dataset));
        self::assertTrue($policy->canBenchmark($dataset));
    }

    #[Test]
    public function only_approved_development_dataset_is_eligible_for_training_and_tuning(): void
    {
        $policy = new TrainingDatasetTrustPolicy;
        $approved = new EstimateGenerationTrainingDataset(['dataset_type' => 'development', 'status' => 'approved']);
        $draft = new EstimateGenerationTrainingDataset(['dataset_type' => 'development', 'status' => 'draft']);

        self::assertTrue($policy->canTrain($approved));
        self::assertTrue($policy->canTuneRules($approved));
        self::assertFalse($policy->canBenchmark($draft));
    }

    #[Test]
    public function benchmark_metrics_reject_unknown_names_before_persistence(): void
    {
        $repository = new BenchmarkRunRepository(new TrainingDatasetTrustPolicy);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('benchmark_metric_not_allowed');
        $repository->complete('unused', ['secret_acceptance_score' => ['macro' => 1]], []);
    }
}

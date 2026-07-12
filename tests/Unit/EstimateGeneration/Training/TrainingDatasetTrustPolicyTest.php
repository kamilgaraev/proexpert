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
        $store = new class implements \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore
        {
            public function read(string $path, int $maxBytes): string
            {
                return '';
            }
        };
        $repository = new BenchmarkRunRepository(new TrainingDatasetTrustPolicy, $store);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('benchmark_metric_not_allowed');
        $repository->complete(1, 'unused', ['secret_acceptance_score' => ['macro' => 1]], [], durationMs: 1);
    }

    #[Test]
    public function inline_benchmark_results_reject_sensitive_fields(): void
    {
        $repository = $this->repository('unused');
        $this->expectExceptionMessage('benchmark_sensitive_case_result_rejected');
        $repository->complete(7, 'unused', $this->metrics(), [['case_id' => '1', 'token' => 'secret']], durationMs: 1);
    }

    #[Test]
    public function inline_results_reject_normalized_sensitive_key_variants(): void
    {
        $repository = $this->repository('unused');
        $this->expectExceptionMessage('benchmark_sensitive_case_result_rejected');
        $repository->complete(7, 'unused', $this->metrics(), [['meta' => ['Access-Token-Value' => 'secret']]], durationMs: 1);
    }

    #[Test]
    public function inline_results_reject_excessive_recursion_depth(): void
    {
        $value = ['case_id' => '1'];
        for ($depth = 0; $depth < 40; $depth++) {
            $value = ['nested' => $value];
        }

        $repository = $this->repository('unused');
        $this->expectExceptionMessage('benchmark_case_results_complexity_exceeded');
        $repository->complete(7, 'unused', $this->metrics(), [$value], durationMs: 1);
    }

    #[Test]
    public function external_results_require_content_addressed_immutable_key(): void
    {
        $contents = 'payload';
        $repository = $this->repository($contents);
        $this->expectExceptionMessage('benchmark_results_object_invalid');
        $repository->complete(7, 'unused', $this->metrics(), s3Path: 'org-7/estimate-generation/benchmarks/run.json', durationMs: 1, s3Size: strlen($contents), s3Sha256: hash('sha256', $contents));
    }

    #[Test]
    public function inline_benchmark_results_reject_too_many_cases(): void
    {
        $repository = $this->repository('unused');
        $this->expectExceptionMessage('benchmark_case_results_invalid');
        $repository->complete(7, 'unused', $this->metrics(), array_fill(0, 1001, ['case_id' => '1']), durationMs: 1);
    }

    #[Test]
    public function external_results_require_exact_tenant_object_integrity(): void
    {
        $repository = $this->repository('payload');
        $this->expectExceptionMessage('benchmark_results_object_integrity_mismatch');
        $repository->complete(7, 'unused', $this->metrics(), s3Path: 'org-7/estimate-generation/benchmarks/unused/'.str_repeat('0', 64).'.json', durationMs: 1, s3Size: 7, s3Sha256: str_repeat('0', 64), s3Etag: 'etag', s3ContentType: 'application/json');
    }

    #[Test]
    public function external_results_reject_cross_organization_paths_before_storage_read(): void
    {
        $repository = $this->repository('payload');
        $this->expectExceptionMessage('benchmark_results_object_invalid');
        $repository->complete(7, 'unused', $this->metrics(), s3Path: 'org-8/estimate-generation/benchmarks/run.json', durationMs: 1, s3Size: 7, s3Sha256: hash('sha256', 'payload'));
    }

    private function repository(string $contents): BenchmarkRunRepository
    {
        $store = new class($contents) implements \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore
        {
            public function __construct(private readonly string $contents) {}

            public function read(string $path, int $maxBytes): string
            {
                return $this->contents;
            }
        };

        return new BenchmarkRunRepository(new TrainingDatasetTrustPolicy, $store);
    }

    /** @return array<string, array<string, float>> */
    private function metrics(): array
    {
        return ['technical_success_rate' => ['macro' => 1.0]];
    }
}

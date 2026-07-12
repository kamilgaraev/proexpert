<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkPersistenceTest extends TestCase
{
    #[Test]
    public function migration_pins_dataset_version_and_closes_dataset_run_contracts(): void
    {
        $source = $this->source('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php');

        foreach ([
            'eg_training_dataset_type_chk',
            'eg_training_dataset_status_chk',
            'eg_training_dataset_scope_chk',
            'eg_training_example_review_chk',
            'eg_benchmark_dataset_version_fk',
            'eg_benchmark_json_bounds_chk',
            'eg_benchmark_run_immutable',
            'eg_benchmark_dataset_scope',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }

    #[Test]
    public function repository_has_idempotent_tenant_scoped_terminal_transitions_and_bounded_results(): void
    {
        $source = $this->source('app/BusinessModules/Addons/EstimateGeneration/Benchmark/BenchmarkRunRepository.php');

        self::assertStringContainsString("->where('organization_id', \$dataset->organization_id)", $source);
        self::assertStringContainsString("->where('idempotency_key', \$idempotencyKey)", $source);
        self::assertStringContainsString('lockForUpdate()', $source);
        self::assertStringContainsString('MAX_JSON_BYTES = 1_048_576', $source);
        self::assertStringContainsString('exactly_one_case_results_location_required', $source);
        self::assertStringContainsString('benchmark_run_is_terminal', $source);
        self::assertStringContainsString('org-{$run->organization_id}/', $source);
        self::assertStringNotContainsString("'local'", $source);
    }

    #[Test]
    public function learning_entrypoints_are_fenced_by_trust_and_human_review(): void
    {
        $source = $this->source('app/BusinessModules/Addons/EstimateGeneration/Services/Training/EstimateGenerationTrainingDatasetService.php');

        self::assertGreaterThanOrEqual(2, substr_count($source, '$this->trustPolicy->assertCanProcess($dataset)'));
        self::assertStringContainsString('$this->trustPolicy->canTrain($dataset)', $source);
        self::assertStringContainsString('$trainingExample->reviewed_by === null', $source);
        self::assertStringContainsString('$trainingExample->reviewed_at === null', $source);
        self::assertStringNotContainsString('STATUS_PROCESSED', $source);
    }

    #[Test]
    public function dataset_versions_are_appended_under_the_same_tenant_identity(): void
    {
        $source = $this->source('app/BusinessModules/Addons/EstimateGeneration/Services/Training/EstimateGenerationTrainingDatasetService.php');

        self::assertStringContainsString('public function appendVersion(', $source);
        self::assertStringContainsString("->where('organization_id', \$source->organization_id)", $source);
        self::assertStringContainsString("->where('dataset_key', \$source->dataset_key)", $source);
        self::assertStringContainsString("'version' => \$latestVersion + 1", $source);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        self::assertIsString($source);

        return $source;
    }
}

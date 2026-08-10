<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationEvaluationProductionWiringTest extends TestCase
{
    #[Test]
    public function production_binds_a_durable_evaluation_corpus_and_exposes_a_release_gate_caller(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = (string) file_get_contents(
            $root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php',
        );
        $commandPath = $root.'/app/BusinessModules/Addons/EstimateGeneration/Console/Commands/RunEvaluationReleaseGateCommand.php';
        $migrationPath = $root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/'
            .'2026_08_10_000250_create_evaluation_corpus.php';

        self::assertStringContainsString('EvaluationCorpusRepository::class', $provider);
        self::assertStringContainsString('EloquentEvaluationCorpusRepository::class', $provider);
        self::assertStringContainsString('RunEvaluationReleaseGateCommand::class', $provider);
        self::assertFileExists($commandPath);

        $command = (string) file_get_contents($commandPath);
        self::assertStringContainsString('EvaluationReleaseGate', $command);
        self::assertStringContainsString('reviewedCorpus', $command);

        self::assertFileExists($migrationPath);
        $migration = (string) file_get_contents($migrationPath);
        self::assertStringContainsString("Schema::create('estimate_generation_evaluation_examples'", $migration);
        self::assertStringContainsString('eg_evaluation_example_contract_ck', $migration);
        self::assertStringContainsString("trust_status IN ('candidate', 'reviewed', 'rejected')", $migration);
        self::assertStringContainsString("throw new RuntimeException('Evaluation corpus migration is irreversible.')", $migration);
    }
}

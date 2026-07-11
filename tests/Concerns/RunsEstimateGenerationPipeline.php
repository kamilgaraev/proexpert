<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;

trait RunsEstimateGenerationPipeline
{
    private function runGenerationPipeline(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $attempt = (string) ($session->input_payload['generation_attempt_id'] ?? '');
        self::assertNotSame('', $attempt);
        $snapshot = FailureExecutionSnapshot::capture($session, 'test_generation_pipeline', $attempt);
        $pipeline = app(DraftPipelineEntrypoint::class);

        foreach (ProcessingStage::cases() as $expectedStage) {
            $run = $pipeline->run($snapshot);
            self::assertSame($expectedStage, $run->executedStage);
        }

        return EstimateGenerationSession::query()->findOrFail($session->getKey());
    }
}

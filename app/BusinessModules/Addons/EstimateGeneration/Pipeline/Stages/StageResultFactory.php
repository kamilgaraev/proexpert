<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStagePayload;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use Illuminate\Support\Facades\Log;

final readonly class StageResultFactory
{
    public function __construct(private PipelineArtifactStore $artifacts, private PipelineDefinitionGraph $graph) {}

    public function make(PipelineContext $context, ProcessingStage $stage, array $data, array $metrics = [], array $warnings = []): PipelineStageResult
    {
        $definition = $this->graph->get($stage);
        if ($context->stage !== $stage) {
            throw new \DomainException('Pipeline stage context does not match the executor.');
        }
        $payload = PipelineStagePayload::from($stage, $data);
        Log::info('estimate_generation.pipeline_artifact_write', [
            'session_id' => $context->sessionId,
            'stage' => $stage->value,
            'phase' => 'started',
        ]);
        $artifact = $this->artifacts->write($context, $definition, $payload->data);
        Log::info('estimate_generation.pipeline_artifact_write', [
            'session_id' => $context->sessionId,
            'stage' => $stage->value,
            'phase' => 'completed',
        ]);
        $output = PipelineStageOutput::create($definition, $context->inputVersion, $context->dependencyVersions, $artifact);

        return new PipelineStageResult($stage, $output->version, $metrics, $warnings, $output, $data);
    }
}

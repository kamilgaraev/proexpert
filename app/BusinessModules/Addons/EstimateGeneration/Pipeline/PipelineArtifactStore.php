<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface PipelineArtifactStore
{
    public function write(PipelineContext $context, ProcessingStage $stage, array $data): PipelineStageOutput;

    /** @return array<string, mixed> */
    public function read(PipelineContext $context, PipelineStageOutput $reference): array;
}

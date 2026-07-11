<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface PipelineArtifactStore
{
    public function write(PipelineContext $context, StageDefinition $definition, array $data): PipelineArtifactReference;

    /** @return array<string, mixed> */
    public function read(PipelineContext $context, PipelineArtifactReference $reference): array;
}

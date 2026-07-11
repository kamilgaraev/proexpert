<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface GenerationPipelineDataGateway
{
    /** @return array{input: array<string, mixed>, documents: list<array<string, mixed>>, user_id: int|null} */
    public function source(PipelineContext $context): array;
}

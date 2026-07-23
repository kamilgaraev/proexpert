<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;

interface WorkCompositionLlmClient
{
    public function isAvailable(): bool;

    /** @return array<string, mixed> */
    public function chat(array $messages, PipelineContext $context, string $candidateSetHash): array;
}

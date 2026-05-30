<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeRerankResultData;

interface NormativeCandidateRerankerInterface
{
    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $candidates
     */
    public function rerank(array $workItem, array $context, array $candidates): NormativeRerankResultData;
}

<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;

interface NormativeCandidateRerankerInterface
{
    public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData;
}

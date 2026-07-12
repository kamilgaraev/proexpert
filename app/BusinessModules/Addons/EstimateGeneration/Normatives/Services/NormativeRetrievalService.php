<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use InvalidArgumentException;

final readonly class NormativeRetrievalService
{
    public function __construct(
        private NormativeCandidateSource $source,
        private NormativeHardGate $hardGate,
        private int $limit,
        private ?string $semanticIndexVersion,
    ) {
        if ($limit < 1 || $limit > 32) {
            throw new InvalidArgumentException('Candidate limit must be between 1 and 32.');
        }
    }

    public function retrieve(WorkIntentData $intent): NormativeCandidateSetData
    {
        $candidates = $this->source->find(
            $intent->organizationId, $intent->projectId, $intent->datasetVersion,
            $intent->intent, $this->limit, $this->semanticIndexVersion,
        );
        usort($candidates, static function ($left, $right): int {
            $leftCombined = $left->lexicalScore + ($left->semanticScore ?? 0.0);
            $rightCombined = $right->lexicalScore + ($right->semanticScore ?? 0.0);

            return $rightCombined <=> $leftCombined ?: strcmp($left->id, $right->id);
        });

        return $this->hardGate->filter($intent, array_slice($candidates, 0, $this->limit));
    }
}

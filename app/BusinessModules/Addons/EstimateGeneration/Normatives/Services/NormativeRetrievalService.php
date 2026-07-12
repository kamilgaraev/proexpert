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
        private NormativeScoring $scoring = new NormativeScoring,
    ) {
        if ($limit < 1 || $limit > 32) {
            throw new InvalidArgumentException('Candidate limit must be between 1 and 32.');
        }
    }

    public function retrieve(WorkIntentData $intent): NormativeCandidateSetData
    {
        $candidates = $this->source->find(
            $intent->organizationId, $intent->projectId, $intent->datasetVersion,
            $intent->intent, min(128, max(64, $this->limit * 4)), $this->semanticIndexVersion,
        );
        $ranked = $this->scoring->rank(array_map(static fn ($candidate): array => [
            'id' => $candidate->id, 'lexical' => $candidate->lexicalScore, 'semantic' => $candidate->semanticScore,
        ], $candidates));
        $byId = array_column($candidates, null, 'id');
        $candidates = array_map(static fn (array $score) => $byId[$score['id']], $ranked);

        return $this->hardGate->filter($intent, array_slice($candidates, 0, $this->limit));
    }
}

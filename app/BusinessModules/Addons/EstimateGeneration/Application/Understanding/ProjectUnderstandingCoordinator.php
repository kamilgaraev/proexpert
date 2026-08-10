<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use InvalidArgumentException;

final readonly class ProjectUnderstandingCoordinator
{
    public function __construct(
        private ProjectModelRepository $models,
        private TargetedConflictResolver $conflicts,
        private CrossDocumentFactArbitratorFactory $arbitrators,
        private ProjectUnderstandingBudget $budget,
    ) {}

    public function refresh(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $checkpointClaimToken,
        int $logicalAttempt,
    ): ProjectUnderstandingResult {
        $preflight = $this->models->understandingPreflight(
            $organizationId,
            $projectId,
            $sessionId,
            $this->budget->maxFacts,
            $this->budget->maxEvidenceItems,
            CrossDocumentFactLinker::MAX_EVIDENCE_PER_FACT,
            $this->budget->maxEvidencePayloadBytes,
            $this->budget->maxEvidenceBytesPerItem,
        );
        if (! ($preflight['within_budget'] ?? false)) {
            return $this->budgetLimitation();
        }
        $capture = $this->models->snapshotForUnderstanding(
            $organizationId,
            $projectId,
            $sessionId,
            $this->budget->maxFacts + 1,
        );
        $snapshot = $capture['snapshot'];
        $inputFingerprint = $capture['token'];
        $verified = $this->models->understandingPreflight(
            $organizationId,
            $projectId,
            $sessionId,
            $this->budget->maxFacts,
            $this->budget->maxEvidenceItems,
            CrossDocumentFactLinker::MAX_EVIDENCE_PER_FACT,
            $this->budget->maxEvidencePayloadBytes,
            $this->budget->maxEvidenceBytesPerItem,
        );
        if (! ($verified['within_budget'] ?? false) || $this->preflightVersion($preflight) !== $this->preflightVersion($verified)) {
            return $this->budgetLimitation();
        }
        if ($snapshot->facts === []) {
            return new ProjectUnderstandingResult([], [], [], [$this->conflicts->insufficientEvidence()], 0);
        }
        $sourceVersions = array_values(array_unique(array_map(
            static fn (Fact $fact): string => $fact->sourceVersion,
            $snapshot->facts,
        )));
        if (count($sourceVersions) !== 1) {
            throw new InvalidArgumentException('Current project model contains mixed source versions.');
        }
        $sourceVersion = $sourceVersions[0];
        $replayed = $this->models->replayUnderstanding(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
        );
        if ($replayed !== null) {
            return new ProjectUnderstandingResult(
                $replayed['links'] ?? [],
                $replayed['conflicts'] ?? [],
                $replayed['questions'] ?? [],
                $replayed['limitations'] ?? [],
                (int) ($replayed['provider_calls'] ?? 0),
            );
        }
        $linker = new CrossDocumentFactLinker(
            $this->conflicts,
            $this->arbitrators->create(
                $organizationId,
                $projectId,
                $sessionId,
                $checkpointClaimToken,
                $logicalAttempt,
            ),
            $this->budget->maxCandidatesPerGroup,
            $this->budget,
        );
        $result = $linker->link($snapshot->entities, $snapshot->facts, $snapshot->evidence);
        $saved = $this->models->replaceUnderstanding(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $result->links,
            $result->conflicts,
            $result->questions,
            $result->limitations,
            $result->providerCalls,
        );
        if (! $saved) {
            return new ProjectUnderstandingResult([], [], [], [$this->conflicts->staleSnapshot()], $result->providerCalls);
        }

        return $result;
    }

    private function budgetLimitation(): ProjectUnderstandingResult
    {
        return new ProjectUnderstandingResult([], [], [], [$this->conflicts->budgetExceeded()], 0);
    }

    private function preflightVersion(array $preflight): string
    {
        unset($preflight['within_budget']);

        return hash('sha256', json_encode($preflight, JSON_THROW_ON_ERROR));
    }
}

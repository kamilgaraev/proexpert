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
            return $this->persistBudgetLimitation($organizationId, $projectId, $sessionId, $preflight);
        }
        $snapshot = $this->models->snapshot(
            $organizationId,
            $projectId,
            $sessionId,
            $this->budget->maxFacts + 1,
        );
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
            return $this->persistBudgetLimitation($organizationId, $projectId, $sessionId, $verified);
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
        $inputFingerprint = ProjectUnderstandingInputFingerprint::fromSnapshot($snapshot);
        $stored = $this->models->currentUnderstanding($organizationId, $projectId, $sessionId);
        if (($stored['source_version'] ?? null) === $sourceVersion
            && ($stored['input_fingerprint'] ?? null) === $inputFingerprint) {
            return new ProjectUnderstandingResult(
                $stored['links'] ?? [],
                $stored['conflicts'] ?? [],
                $stored['questions'] ?? [],
                $stored['limitations'] ?? [],
                (int) ($stored['provider_calls'] ?? 0),
            );
        }
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
        $this->models->replaceUnderstanding(
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

        return $result;
    }

    private function persistBudgetLimitation(
        int $organizationId,
        int $projectId,
        int $sessionId,
        array $preflight,
    ): ProjectUnderstandingResult {
        $result = new ProjectUnderstandingResult([], [], [], [$this->conflicts->budgetExceeded()], 0);
        $sourceVersion = $preflight['source_version'] ?? null;
        if (is_string($sourceVersion)) {
            $this->models->replaceUnderstanding(
                $organizationId,
                $projectId,
                $sessionId,
                $sourceVersion,
                hash('sha256', json_encode($preflight, JSON_THROW_ON_ERROR)),
                [],
                [],
                [],
                $result->limitations,
                0,
            );
        }

        return $result;
    }

    private function preflightVersion(array $preflight): string
    {
        unset($preflight['within_budget']);

        return hash('sha256', json_encode($preflight, JSON_THROW_ON_ERROR));
    }
}

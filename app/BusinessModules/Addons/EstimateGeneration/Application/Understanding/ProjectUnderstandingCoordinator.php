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
            return ProjectUnderstandingResult::unresolved([$this->conflicts->insufficientEvidence()]);
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
            return $this->persistedResult(
                $sourceVersion,
                $inputFingerprint,
                $replayed['links'] ?? [],
                $replayed['conflicts'] ?? [],
                $replayed['questions'] ?? [],
                $replayed['limitations'] ?? [],
                (int) ($replayed['provider_calls'] ?? 0),
                $this->hasOnlyPlanningUnresolvedFacts($snapshot->facts),
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
            return ProjectUnderstandingResult::stale([$this->conflicts->staleSnapshot()], $result->providerCalls);
        }

        return $this->persistedResult(
            $sourceVersion,
            $inputFingerprint,
            $result->links,
            $result->conflicts,
            $result->questions,
            $result->limitations,
            $result->providerCalls,
            $this->hasOnlyPlanningUnresolvedFacts($snapshot->facts),
        );
    }

    private function budgetLimitation(): ProjectUnderstandingResult
    {
        return ProjectUnderstandingResult::unresolved([$this->conflicts->budgetExceeded()]);
    }

    private function persistedResult(
        string $sourceVersion,
        string $inputFingerprint,
        array $links,
        array $conflicts,
        array $questions,
        array $limitations,
        int $providerCalls,
        bool $hasOnlyPlanningUnresolvedFacts,
    ): ProjectUnderstandingResult {
        if ($limitations !== [] && (! $hasOnlyPlanningUnresolvedFacts || $questions !== [] || $conflicts !== [])) {
            return ProjectUnderstandingResult::unresolved(
                $limitations,
                $links,
                $conflicts,
                $questions,
                $providerCalls,
                $sourceVersion,
                $inputFingerprint,
            );
        }

        return ProjectUnderstandingResult::current(
            $sourceVersion,
            $inputFingerprint,
            $links,
            $conflicts,
            $questions,
            $providerCalls,
            $limitations,
        );
    }

    private function hasOnlyPlanningUnresolvedFacts(array $facts): bool
    {
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact || $fact->status === 'confirmed') {
                continue;
            }
            if ($fact->origin !== 'unresolved'
                || ! in_array($fact->type, ['material', 'material_name', 'roof_covering_system'], true)) {
                return false;
            }
        }

        return true;
    }

    private function preflightVersion(array $preflight): string
    {
        unset($preflight['within_budget']);

        return hash('sha256', json_encode($preflight, JSON_THROW_ON_ERROR));
    }
}

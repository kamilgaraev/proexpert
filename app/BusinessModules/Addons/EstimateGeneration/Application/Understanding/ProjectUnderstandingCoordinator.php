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
        $snapshot = $this->models->snapshot(
            $organizationId,
            $projectId,
            $sessionId,
            $this->budget->maxFacts + 1,
        );
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
        $stored = $this->models->currentUnderstanding($organizationId, $projectId, $sessionId);
        if (($stored['source_version'] ?? null) === $sourceVersion) {
            return new ProjectUnderstandingResult(
                $stored['links'] ?? [],
                $stored['conflicts'] ?? [],
                $stored['questions'] ?? [],
                $stored['limitations'] ?? [],
                (int) ($stored['provider_calls'] ?? 0),
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
            $result->links,
            $result->conflicts,
            $result->questions,
            $result->limitations,
            $result->providerCalls,
        );

        return $result;
    }
}

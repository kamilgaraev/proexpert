<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\DeterministicGeometryCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisRunner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\RunProjectSynthesis;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;

final readonly class ProjectUnderstandingCoordinator
{
    public function __construct(
        private ProjectModelRepository $models,
        private TargetedConflictResolver $conflicts,
        private CrossDocumentFactArbitratorFactory $arbitrators,
        private ProjectUnderstandingBudget $budget,
        private ProjectSynthesisRunner $synthesis,
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
        $snapshotToken = $capture['token'];
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
            return ProjectUnderstandingResult::unresolved(['empty_facts']);
        }
        $sourceVersions = array_values(array_unique(array_map(
            static fn (Fact $fact): string => $fact->sourceVersion,
            $snapshot->facts,
        )));
        sort($sourceVersions, SORT_STRING);
        $quantities = [];
        foreach ($sourceVersions as $version) {
            $quantities = [
                ...$quantities,
                ...array_values(array_filter($this->models->currentDerivedQuantities(
                    $organizationId,
                    $projectId,
                    $sessionId,
                    $version,
                    $this->budget->maxFacts,
                ), static fn (DerivedQuantity $quantity): bool => $quantity->formulaVersion
                    !== DeterministicGeometryCalculator::FORMULA_VERSION)),
            ];
        }
        $quantities = [
            ...$quantities,
            ...$this->models->currentDerivedQuantitiesForFormulaVersion(
                $organizationId,
                $projectId,
                $sessionId,
                DeterministicGeometryCalculator::FORMULA_VERSION,
                $this->budget->maxFacts,
            ),
        ];
        $roleFingerprints = $this->models->completedSynthesisRoleFingerprints(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersions,
        );
        $factIds = array_map(static fn (Fact $fact): string => $fact->id, $snapshot->facts);
        $decisions = $this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, $factIds);
        $synthesisInput = new ProjectSynthesisInput(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersions,
            array_map(static fn (Fact $fact): array => [
                'id' => $fact->id,
                'entity_id' => $fact->entityId,
                'type' => $fact->type,
                'value' => $fact->value,
                'unit' => $fact->unit,
                'status' => $fact->status,
                'origin' => $fact->origin,
                'evidence_ids' => $fact->evidenceIds,
                'source_version' => $fact->sourceVersion,
                'version' => $fact->version,
                'current' => $fact->status !== 'invalidated',
            ], $snapshot->facts),
            array_map(static fn (DerivedQuantity $quantity): array => [
                'id' => $quantity->id,
                'logical_id' => $quantity->logicalId,
                'entity_id' => $quantity->entityId,
                'formula_identity' => $quantity->formulaIdentity,
                'formula_version' => $quantity->formulaVersion,
                'value' => $quantity->value,
                'unit' => $quantity->unit,
                'status' => $quantity->status,
                'source_version' => $quantity->sourceVersion,
                'exact_identity' => $quantity->exactIdentity,
            ], $quantities),
            array_map(static fn ($decision): array => [
                'id' => $decision->id,
                'version' => $decision->version,
                'selected_fact_id' => $decision->selectedFactId,
            ], $decisions),
            [
                'arbiter' => $roleFingerprints['arbiter'],
                'geometry_expert' => $roleFingerprints['geometry_expert'],
            ],
            RunProjectSynthesis::PROMPT_CONTRACT,
        );
        $sourceVersion = $synthesisInput->aggregateSourceVersion();
        $inputFingerprint = $synthesisInput->fingerprint();
        $replayed = $this->models->replayUnderstanding(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $snapshotToken,
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
        $selection = $this->synthesis->run($synthesisInput, $result->links, $result->questions);
        $acceptedLinks = array_values(array_filter(
            $result->links,
            static fn (array $link): bool => in_array($link['id'] ?? null, $selection->acceptedLinkIds, true),
        ));
        $questions = array_values(array_filter(
            $result->questions,
            static fn (array $question): bool => in_array(
                $question['conflict_id'] ?? null,
                $selection->questionConflictIds,
                true,
            ),
        ));
        $saved = $this->models->replaceUnderstanding(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $snapshotToken,
            $acceptedLinks,
            $result->conflicts,
            $questions,
            $result->limitations,
            $result->providerCalls + 1,
        );
        if (! $saved) {
            return ProjectUnderstandingResult::stale([$this->conflicts->staleSnapshot()], $result->providerCalls);
        }

        return $this->persistedResult(
            $sourceVersion,
            $inputFingerprint,
            $acceptedLinks,
            $result->conflicts,
            $questions,
            $result->limitations,
            $result->providerCalls + 1,
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

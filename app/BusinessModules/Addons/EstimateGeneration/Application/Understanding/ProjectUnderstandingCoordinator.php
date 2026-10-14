<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\EloquentProjectSynthesisAllowance;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisAllowance;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisRunner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectUnderstandingFingerprint;

final readonly class ProjectUnderstandingCoordinator
{
    public function __construct(
        private ProjectModelRepository $models,
        private TargetedConflictResolver $conflicts,
        private CrossDocumentFactArbitratorFactory $arbitrators,
        private ProjectUnderstandingBudget $budget,
        private ProjectSynthesisRunner $synthesis,
        private ProjectSynthesisAllowance $allowance = new EloquentProjectSynthesisAllowance,
    ) {}

    public function refresh(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $checkpointClaimToken,
        int $logicalAttempt,
        ?\App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope $wireScope = null,
        bool $allowIdentityOnlyPlanning = false,
        bool $allowModelCalls = true,
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
        $synthesisInput = (new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInputFactory($this->models))
            ->capture($organizationId, $projectId, $sessionId, $snapshot, $this->budget->maxFacts, $wireScope);
        $sourceVersion = $synthesisInput->aggregateSourceVersion();
        $approvedParts = $allowModelCalls ? $this->allowance->partLimit($synthesisInput) : 0;
        $inputFingerprint = $approvedParts === null ? $synthesisInput->fingerprint()
            : hash('sha256', $synthesisInput->fingerprint().'|approved-synthesis-parts:'.$approvedParts);
        $inputFingerprint = hash('sha256', ProjectUnderstandingFingerprint::CONTRACT."\0".$inputFingerprint);
        $inputFingerprint = hash('sha256', CrossDocumentFactLinker::CONTRACT."\0".$inputFingerprint);
        if ($allowIdentityOnlyPlanning) {
            $inputFingerprint = hash('sha256', $inputFingerprint.'|identity-only-planning:v1');
        }
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
                $this->hasOnlyPlanningUnresolvedFacts($snapshot->facts, $snapshot->entities, $allowIdentityOnlyPlanning),
                $allowIdentityOnlyPlanning,
            );
        }
        $linker = new CrossDocumentFactLinker(
            $this->conflicts,
            $approvedParts === 0 ? null : $this->arbitrators->create(
                $organizationId,
                $projectId,
                $sessionId,
                $checkpointClaimToken,
                $logicalAttempt,
            ),
            $this->budget->maxCandidatesPerGroup,
            $this->budget,
            $sourceVersion,
        );
        $result = $linker->link($snapshot->entities, $snapshot->facts, $snapshot->evidence);
        $conflicts = [];
        $questionsByConflict = [];
        $evidenceById = [];
        foreach ($snapshot->evidence as $evidence) {
            $evidenceById[$evidence->id] = $evidence;
        }
        foreach ([...$snapshot->conflicts, ...$result->conflicts] as $conflict) {
            $conflicts[$conflict->id] = $conflict;
            $questionsByConflict[$conflict->id] = $this->conflicts->question($conflict, $evidenceById);
        }
        foreach ($result->questions as $question) {
            if (is_string($question['conflict_id'] ?? null)) {
                $questionsByConflict[$question['conflict_id']] = $question;
            }
        }
        $candidateQuestions = array_values($questionsByConflict);
        $candidateLinks = array_map(static fn (array $link): array => [...$link,
            'fact_ids' => $link['candidate_fact_ids'] ?? [$link['left_fact_id'], $link['right_fact_id']]], $result->links);
        $synthesisProviderCalls = 0;
        if ($result->links === [] && $candidateQuestions === []) {
            $selection = new ProjectSynthesisSelection([], []);
        } elseif ($approvedParts === 0) {
            // The linker already validates scope, evidence and deterministic compatibility; retain its original statuses.
            $selection = new ProjectSynthesisSelection(array_column($result->links, 'id'), array_column($candidateQuestions, 'conflict_id'));
        } else {
            $synthesisProviderCalls = count((new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisBatchPlanner)->plan($candidateLinks, $candidateQuestions));
            if ($synthesisProviderCalls + $result->providerCalls > $this->budget->maxProviderCalls) {
                return $this->budgetLimitation();
            }
            $selection = $this->synthesis->run($synthesisInput, $candidateLinks, $candidateQuestions);
        }
        $acceptedLinks = array_values(array_filter(
            $result->links,
            static fn (array $link): bool => in_array($link['id'] ?? null, $selection->acceptedLinkIds, true),
        ));
        $questions = array_values(array_filter(
            $candidateQuestions,
            static fn (array $question): bool => in_array(
                $question['conflict_id'] ?? null,
                $selection->questionConflictIds,
                true,
            ),
        ));
        foreach ((new VisualInventoryQuestionBuilder)->build($snapshot->entities, $snapshot->facts, $snapshot->evidence) as $question) {
            $questions[$question['conflict_id']] = $question;
        }
        $questions = array_values($questions);
        $saved = $this->models->replaceUnderstanding(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $snapshotToken,
            $acceptedLinks,
            array_values($conflicts),
            $questions,
            $result->limitations,
            $result->providerCalls + $synthesisProviderCalls,
        );
        if (! $saved) {
            return ProjectUnderstandingResult::stale([$this->conflicts->staleSnapshot()], $result->providerCalls);
        }

        return $this->persistedResult(
            $sourceVersion,
            $inputFingerprint,
            $acceptedLinks,
            array_values($conflicts),
            $questions,
            $result->limitations,
            $result->providerCalls + $synthesisProviderCalls,
            $this->hasOnlyPlanningUnresolvedFacts($snapshot->facts, $snapshot->entities, $allowIdentityOnlyPlanning),
            $allowIdentityOnlyPlanning,
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
        bool $allowPartialQuestions = false,
    ): ProjectUnderstandingResult {
        $identityQuestionsOnly = $allowPartialQuestions && $hasOnlyPlanningUnresolvedFacts && $conflicts === []
            && array_diff($limitations, [$this->conflicts->insufficientEvidence()]) === [];
        if ($limitations !== [] && (! $hasOnlyPlanningUnresolvedFacts || ($questions !== [] && ! $identityQuestionsOnly) || $conflicts !== [])) {
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

    private function hasOnlyPlanningUnresolvedFacts(array $facts, array $entities = [], bool $allowIdentityOnlyPlanning = false): bool
    {
        $entitiesById = array_column($entities, null, 'id');
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact || $fact->status === 'confirmed') {
                continue;
            }
            $entity = $entitiesById[$fact->entityId] ?? null;
            if ($allowIdentityOnlyPlanning && $fact->type === 'room_name' && $fact->status === 'candidate'
                && $fact->unit === null && $fact->evidenceIds !== [] && $entity !== null && $entity->type === 'room'
                && ($entity->attributes['properties']['identity_only'] ?? false) === true
                && ($entity->attributes['properties']['requires_confirmation'] ?? false) === true) {
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

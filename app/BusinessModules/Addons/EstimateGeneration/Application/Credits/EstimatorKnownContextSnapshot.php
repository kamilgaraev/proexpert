<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactLinker;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentEstimateScope;
use DomainException;
use InvalidArgumentException;

/** Quotes contain hashes and counts only. Source contents stay in scoped server repositories. */
final readonly class EstimatorKnownContextSnapshot
{
    public function __construct(private ProjectModelRepository $models, private EstimateComposerInputFactory $composer,
        private EstimateAuditInputFactory $audit, private ProjectSynthesisInputFactory $synthesis,
        private EstimateCompositionProjector $projection, private TargetedConflictResolver $conflicts,
        private ProjectUnderstandingBudget $budget, private EstimatorKnownContextParts $parts) {}

    public function capture(EstimateGenerationSession $session, bool $complex, ?array $wirePolicy = null, ?string $localEstimateKey = null, ?array $roleLimits = null): array
    {
        try {
            return $this->captureCurrent($session, $complex, $wirePolicy, $localEstimateKey, $roleLimits);
        } catch (InvalidArgumentException $error) {
            $code = match ($error->getMessage()) {
                'estimate_composer_snapshot_changed', 'estimate_audit_snapshot_changed', 'estimate_composer_candidate_source_invalid',
                'estimate_composer_quantity_operand_missing' => 'estimator_known_context_changed',
                'estimate_composer_entity_context_too_large', 'estimate_composer_operation_plan_limit_exceeded',
                'estimate_audit_context_requires_source_split', 'estimate_audit_evidence_requires_section_split', 'estimate_audit_input_too_large',
                'project_synthesis_input_requires_source_split', 'project_synthesis_parts_exceed_limit',
                'project_synthesis_snapshot_limit_exceeded', 'estimate_composer_fact_limit_exceeded',
                'estimate_audit_fact_limit_exceeded' => 'estimator_known_context_requires_source_split',
                default => null,
            };
            if ($code === null) {
                throw $error;
            }
            throw new DomainException($code, previous: $error);
        }
    }

    private function captureCurrent(EstimateGenerationSession $session, bool $complex, ?array $wirePolicy, ?string $localEstimateKey, ?array $roleLimits): array
    {
        $wirePolicy = EstimatorTextWirePolicy::validate($wirePolicy ?? EstimatorTextWirePolicy::capture());
        $organizationId = (int) $session->organization_id;
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->id;
        $this->assertWithinBudget($organizationId, $projectId, $sessionId);
        $capture = $this->models->snapshotForUnderstanding($organizationId, $projectId, $sessionId, $this->budget->maxFacts + 1);
        $snapshot = $capture['snapshot'];
        if (count($snapshot->facts) > $this->budget->maxFacts) {
            throw new DomainException('estimator_known_context_requires_source_split');
        }
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        if ($localEstimateKey !== null && $draft !== []) {
            $draft['local_estimates'] = array_values(array_filter($draft['local_estimates'] ?? [],
                static fn (array $local): bool => ($local['key'] ?? null) === $localEstimateKey));
        }
        $candidates = $this->projection->candidates($draft['local_estimates'] ?? []);
        $workCount = 0;
        foreach ($draft['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                $workCount += count($section['work_items'] ?? []);
            }
        }
        if (count($candidates) > 2000 || $workCount > 2000) {
            throw new DomainException('estimator_known_context_requires_source_split');
        }
        $currentFacts = array_fill_keys(array_map(static fn ($fact): string => $fact->id, $snapshot->facts), true);
        $staleDraft = (int) ($session->input_payload['technology_selection_revision'] ?? 0)
            > (int) ($draft['evaluation_policy']['technology_revision'] ?? 0);
        foreach ($candidates as $candidate) {
            if (array_diff($candidate['source_fact_ids'], array_keys($currentFacts)) !== []) {
                $staleDraft = true;
            }
        }
        $currentCandidates = $staleDraft ? [] : $candidates;
        $synthesisInput = $snapshot->facts === [] ? null : $this->synthesis->capture($organizationId, $projectId, $sessionId,
            $snapshot, $this->budget->maxFacts);
        $quantities = [];
        foreach ($synthesisInput?->derivedQuantities ?? [] as $quantity) {
            if (($quantity['status'] ?? null) === 'confirmed' && is_numeric($quantity['value'] ?? null) && (float) $quantity['value'] > 0) {
                $quantities[] = ['id' => $quantity['id'], 'value' => $quantity['value'], 'unit' => $quantity['unit'],
                    'formula_inputs' => ['entity_id' => $quantity['entity_id'],
                        'operands' => array_map(static fn (string $id): array => ['fact_id' => $id], $quantity['operand_fact_ids'])]];
            }
        }
        if (count($quantities) > 2000) {
            throw new DomainException('estimator_known_context_requires_source_split');
        }
        $composerInput = $snapshot->facts === [] && $currentCandidates === [] ? null
            : $this->composer->capture($organizationId, $projectId, $sessionId, $currentCandidates, $quantities, [],
                allowedWorkSections: ($session->input_payload['evaluation_mode'] ?? null) === 'universal'
                    ? ($session->input_payload['selected_sections'] ?? []) : []);
        $auditInput = $staleDraft || $workCount === 0 ? null : $this->audit->capture($organizationId, $projectId, $sessionId, $draft,
            draftView: $wirePolicy['audit_draft_view'] ?? \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditDraftView::LEGACY);
        $links = $questions = [];
        if ($complex && $synthesisInput !== null) {
            // No arbitrator is constructed: quote preparation has no paid side effects.
            $linked = (new CrossDocumentFactLinker($this->conflicts, null, $this->budget->maxCandidatesPerGroup, $this->budget))
                ->link($snapshot->entities, $snapshot->facts, $snapshot->evidence);
            $links = array_map(static fn (array $link): array => [...$link,
                'fact_ids' => $link['candidate_fact_ids'] ?? [$link['left_fact_id'], $link['right_fact_id']]], $linked->links);
            $evidence = [];
            foreach ($snapshot->evidence as $record) {
                $evidence[$record->id] = $record;
            }
            $byConflict = [];
            foreach ([...$snapshot->conflicts, ...$linked->conflicts] as $conflict) {
                $byConflict[$conflict->id] = $this->conflicts->question($conflict, $evidence);
            }
            foreach ($linked->questions as $question) {
                if (is_string($question['conflict_id'] ?? null)) {
                    $byConflict[$question['conflict_id']] = $question;
                }
            }
            $questions = array_values($byConflict);
        }
        $measured = $this->parts->measure($composerInput, $auditInput, $complex ? $synthesisInput : null, $links, $questions,
            $wirePolicy, $roleLimits);
        $equipmentScope = [];
        if (($session->input_payload['evaluation_mode'] ?? null) === 'universal'
            && in_array('equipment', $session->input_payload['selected_sections'] ?? [], true)
            && ($session->input_payload['profile_id'] ?? null) !== 'industrial') {
            $answerIds = EquipmentEstimateScope::answerFactIds($snapshot);
            $scopes = EquipmentEstimateScope::select($snapshot, $answerIds === [] ? []
                : $this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, $answerIds));
            if ($scopes !== []) {
                $operationCount = EquipmentEstimateScope::workCount($scopes);
                $equipmentScope = ['equipment_work_scope' => ['version' => 'equipment-estimate-scope:v1',
                    'fingerprint' => hash('sha256', CanonicalPipelineJson::encode($scopes)),
                    'operation_count' => $operationCount, 'expansion_delta' => max(0, $operationCount - 2)]];
            }
        }
        $this->assertWithinBudget($organizationId, $projectId, $sessionId);
        $verified = $this->models->snapshotForUnderstanding($organizationId, $projectId, $sessionId, $this->budget->maxFacts + 1);
        if (! hash_equals((string) $capture['token'], (string) $verified['token'])) {
            throw new DomainException('estimator_known_context_changed');
        }

        return [...$measured, 'fingerprint' => hash('sha256', CanonicalPipelineJson::encode([
            'model_snapshot' => $capture['token'], 'parts' => $measured['fingerprint'], 'saved_draft' => $draft, ...$equipmentScope])),
            ...$equipmentScope,
            'known_candidate_count' => count($candidates), 'known_fact_count' => count($snapshot->facts),
            'known_work_count' => $workCount, 'known_quantity_count' => count($quantities),
            'draft_context_status' => $draft === [] ? 'absent' : ($staleDraft ? 'recomposition_required' : 'current'),
            'saved_draft_hash' => hash('sha256', CanonicalPipelineJson::encode($draft)), 'basis' => 'known_context_lower_bound'];
    }

    private function assertWithinBudget(int $organizationId, int $projectId, int $sessionId): void
    {
        $preflight = $this->models->understandingPreflight($organizationId, $projectId, $sessionId, $this->budget->maxFacts,
            $this->budget->maxEvidenceItems, CrossDocumentFactLinker::MAX_EVIDENCE_PER_FACT,
            $this->budget->maxEvidencePayloadBytes, $this->budget->maxEvidenceBytesPerItem);
        if (($preflight['within_budget'] ?? false) !== true) {
            throw new DomainException('estimator_known_context_requires_source_split');
        }
    }
}

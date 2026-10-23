<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use InvalidArgumentException;

final readonly class EstimateComposerInputFactory
{
    public function __construct(
        private ProjectModelRepository $models,
        private int $maxFacts,
        private ?EstimateGenerationLearningEvidenceService $learning = null,
        private ?\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource $cellSources = null,
        private ?\App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog $clarifications = null,
    ) {
        if ($maxFacts < 1 || $maxFacts > 10000) {
            throw new InvalidArgumentException('estimate_composer_fact_limit_invalid');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $derivedQuantities
     * @param  list<array<string, mixed>>  $missingDocuments
     */
    public function capture(
        int $organizationId,
        int $projectId,
        int $sessionId,
        array $candidates,
        array $derivedQuantities,
        array $missingDocuments,
        ?AiSessionWireScope $wireScope = null,
        array $allowedWorkSections = [],
        ?string $evaluationMode = null,
        array $answerPolicy = [],
        ?string $localEstimateKey = null,
    ): EstimateComposerInput {
        $capture = $this->models->snapshotForPlanning(
            $organizationId,
            $projectId,
            $sessionId,
            $this->maxFacts + 1,
        );
        $snapshot = $capture['snapshot'];
        $entities = array_column($snapshot->entities, null, 'id');
        $evidence = array_column($snapshot->evidence, null, 'id');
        if (count($snapshot->facts) > $this->maxFacts) {
            throw new InvalidArgumentException('estimate_composer_fact_limit_exceeded');
        }
        $factIds = array_map(static fn (Fact $fact): string => $fact->id, $snapshot->facts);
        $factIdSet = array_fill_keys($factIds, true);
        foreach ($candidates as $candidate) {
            foreach (is_array($candidate['source_fact_ids'] ?? null) ? $candidate['source_fact_ids'] : [] as $factId) {
                if (! is_string($factId) || ! isset($factIdSet[$factId])) {
                    throw new InvalidArgumentException('estimate_composer_candidate_source_invalid');
                }
            }
        }
        $decisions = $this->models->decisionsForSelectedFacts(
            $organizationId,
            $projectId,
            $sessionId,
            $factIds,
        );
        $verified = $this->models->snapshotForPlanning(
            $organizationId,
            $projectId,
            $sessionId,
            $this->maxFacts + 1,
        );
        $workContexts = $this->clarifications?->currentWorkContexts($organizationId, $projectId, $sessionId, $snapshot, $decisions, $localEstimateKey) ?? [];
        $verifiedWorkContexts = $this->clarifications?->currentWorkContexts($organizationId, $projectId, $sessionId, $verified['snapshot'], $decisions, $localEstimateKey) ?? [];
        if (! hash_equals((string) $capture['token'], (string) $verified['token']) || $workContexts !== $verifiedWorkContexts) {
            throw new InvalidArgumentException('estimate_composer_snapshot_changed');
        }
        $sourceDecisions = $decisions;

        $answerContexts = [];
        $checkedAnswers = [];
        $hasComposerAnswer = false;
        $reviewContexts = [];
        $eligibleFacts = [];
        $policy = $answerPolicy === [] ? null : ResolveCurrentEstimateClarification::composerPolicy($answerPolicy);
        foreach ($snapshot->facts as $fact) {
            if (str_starts_with($fact->type, 'engineering_parameter_')) {
                continue;
            }
            if ($fact->type === 'evaluation_answer' && is_array($fact->value)
                && ($fact->value['composer_context']['kind'] ?? null) === 'work_technology') {
                $hasComposerAnswer = true;
                continue;
            }
            if (isset($entities[$fact->entityId]?->attributes['properties']['document_cell_review_anchor'])) {
                $reviewContexts[$fact->id] = $this->learning?->cellReviewContext([$organizationId, $projectId, $sessionId], $snapshot, $decisions, $fact);
                if ($reviewContexts[$fact->id] === null) {
                    continue;
                }
            }
            if ($fact->type === 'evaluation_answer' && is_array($fact->value) && ($fact->value['kind'] ?? null) === 'composer_answer') {
                $hasComposerAnswer = true;
                if ($answerPolicy !== [] && ($policy === null || $policy['mode'] !== ($evaluationMode ?? 'legacy')
                    || ($policy['mode'] === 'universal' && $policy['selected_sections'] !== $allowedWorkSections))) {
                    throw new InvalidArgumentException('estimate_composer_snapshot_changed');
                }
                $answerContext = ResolveCurrentEstimateClarification::composerAnswerContext($fact, $snapshot, $decisions, $answerPolicy, $checkedAnswers);
                if ($answerContext === null) {
                    continue;
                }
                $answerContexts[$fact->id] = $answerContext;
            }
            $eligibleFacts[] = $fact;
        }
        if ($hasComposerAnswer || $reviewContexts !== []) {
            $eligibleIds = array_fill_keys(array_column($eligibleFacts, 'id'), true);
            foreach ($candidates as $candidate) {
                if (array_diff($candidate['source_fact_ids'] ?? [], array_keys($eligibleIds)) !== []) {
                    throw new InvalidArgumentException('estimate_composer_candidate_source_invalid');
                }
            }
            $decisions = array_values(array_filter($decisions, static fn (Decision $decision): bool => $decision->selectedFactId === null || isset($eligibleIds[$decision->selectedFactId])));
        }
        $eligibleById = $answerContexts === [] ? [] : array_column($eligibleFacts, null, 'id');
        $snapshotById = $answerContexts === [] ? [] : array_column($snapshot->facts, null, 'id');
        $workSourceClaims = EstimateComposerInput::workSourceClaimContexts($workContexts) !== [];
        $facts = array_map(function (Fact $fact) use ($entities, $evidence, $allowedWorkSections, $evaluationMode, $answerContexts, $eligibleById, $snapshotById, $reviewContexts, $workSourceClaims): array {
            $entity = $entities[$fact->entityId] ?? null;
            $related = [];
            foreach (['room_id', 'wall_id', 'roof_id'] as $relation) {
                $id = $entity?->attributes[$relation] ?? null;
                if (is_string($id) && isset($entities[$id])) {
                    $related[] = $id;
                }
            }

            $context = [];
            if ($workSourceClaims && ($documentId = $this->sourceDocumentId($fact, $evidence)) !== null) {
                $context['source_document_id'] = $documentId;
            }
            if (isset($reviewContexts[$fact->id])) {
                $context['source_review_decision_id'] = $reviewContexts[$fact->id]['candidate']['source_review_decision_id'];
            }
            if ($fact->type === 'evaluation_answer' && is_array($fact->value) && ($fact->value['kind'] ?? null) === 'document_cell_review') {
                return [...$this->fact($fact), 'value' => ['kind' => 'document_cell_review', 'decision' => $fact->value['decision'],
                    'source_review_decision_id' => $fact->value['decision_id'], 'numeric_parameters_confirmed' => false],
                    'entity_type' => $entity?->type, 'related_entity_ids' => []];
            }
            if (isset($answerContexts[$fact->id])) {
                return [...$this->fact($fact), 'value' => ['kind' => 'composer_answer', 'answer' => $fact->value['other'],
                    'question_key' => $fact->value['question_key'], 'numeric_parameters_confirmed' => false,
                    ...(isset($answerContexts[$fact->id]['target_entity_id']) ? ['target_entity_id' => $answerContexts[$fact->id]['target_entity_id']] : [])],
                    'entity_type' => $entity?->type,
                    'related_entity_ids' => $this->composerAnswerRelatedEntities($fact, $answerContexts[$fact->id], $snapshotById, $eligibleById, $entities, $evidence),
                    'context_kind' => 'composer_answer',
                    'context_entity_id' => $answerContexts[$fact->id]['entity_id'], 'answer_work_sections' => $answerContexts[$fact->id]['work_sections']];
            }
            if ($fact->type === 'evaluation_answer' && $fact->origin === 'user_input' && $fact->status === 'confirmed'
                && is_array($fact->value) && ($fact->value['kind'] ?? null) === 'initial_description'
                && ($fact->value['numeric_parameters_confirmed'] ?? null) === false) {
                foreach ($fact->evidenceIds as $id) {
                    $proof = $evidence[$id] ?? null;
                    if ($proof?->sourceType === 'user_input' && $proof->nativeReference === 'input_payload.description'
                        && $proof->producerName === 'user_input_normalizer') {
                        if ($evaluationMode === 'legacy') {
                            if ($proof->sourceVersion !== $fact->sourceVersion || ! is_string($fact->value['description'] ?? null)
                                || trim($fact->value['description']) === '') {
                                continue;
                            }
                            $context = ['context_kind' => 'initial_description', 'semantic_work_sections' => EstimateComposerInput::WORK_SECTIONS];
                        } else {
                            $context = ['context_kind' => 'initial_description', 'declared_work_sections' => $allowedWorkSections];
                        }
                        break;
                    }
                }
            }

            return [...$this->fact($fact), 'entity_type' => $entity?->attributes['semantic_type'] ?? $entity?->attributes['properties']['semantic_type'] ?? $entity?->type,
                'related_entity_ids' => array_values(array_unique($related)), ...$context];
        }, $eligibleFacts);
        $contractVersion = RunEstimateComposer::PROMPT_CONTRACT;
        if ($evaluationMode === 'legacy' && array_filter($facts, static fn (array $fact): bool => isset($fact['semantic_work_sections'])) !== []) {
            $allowedWorkSections = EstimateComposerInput::WORK_SECTIONS;
            $contractVersion = RunEstimateComposer::LEGACY_PROMPT_CONTRACT;
        }
        if ($answerContexts !== [] || $workSourceClaims) {
            $contractVersion = $contractVersion === RunEstimateComposer::LEGACY_PROMPT_CONTRACT
                ? RunEstimateComposer::LEGACY_ANSWER_PROMPT_CONTRACT : RunEstimateComposer::ANSWER_PROMPT_CONTRACT;
        }

        $quantities = array_map($this->derivedQuantity(...), $derivedQuantities);
        if ($workContexts !== [] && $this->learning !== null) {
            $references = $this->learning->parameterReferences([$organizationId, $projectId, $sessionId], $snapshot, $sourceDecisions, $answerPolicy, $localEstimateKey);
            foreach ($workContexts as &$entry) {
                $reference = $references[$entry['answer']['fact_id'] ?? ''] ?? null;
                if ($reference !== null && isset($entry['answer']['parameter'])) {
                    $entry['answer']['parameter']['component'] = $reference;
                }
            }
            unset($entry);
            if ($references !== [] && ! hash_equals((string) $capture['token'],
                (string) $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, $this->maxFacts + 1)['token'])) {
                throw new InvalidArgumentException('estimate_composer_snapshot_changed');
            }
        }
        $input = new EstimateComposerInput(
            organizationId: $organizationId,
            projectId: $projectId,
            sessionId: $sessionId,
            snapshotToken: (string) $capture['token'],
            facts: $facts,
            derivedQuantities: $quantities,
            decisions: array_map($this->decision(...), $decisions),
            candidates: $candidates,
            missingDocuments: $missingDocuments,
            contractVersion: $contractVersion,
            wireScope: $wireScope,
            allowedWorkSections: $allowedWorkSections,
            directQuantityBindings: $this->directQuantityBindings($snapshot, $decisions, $quantities, (string) $capture['token'], array_column($facts, 'id')),
            workTechnologyContext: $workContexts,
        );
        if ($input->claimAnswerFactIds !== [] && $this->learning !== null) {
            $references = $this->learning->sourceClaimReferences($organizationId, $projectId, $sessionId, $snapshot, $sourceDecisions, $answerPolicy, $localEstimateKey);
            $references = array_values(array_filter(array_slice($references, 0, EstimateComposerInput::MAX_SOURCE_CLAIMS),
                static fn (mixed $reference): bool => EstimateComposerInput::sourceClaimReference($reference, $facts, $input->claimAnswerFactIds,
                    $input->acceptsWorkSourceClaims() ? $input->workTechnologyContext : [])));
            $input = $input->withSourceClaimReferences($references);
        }
        if ($reviewContexts !== [] && $this->learning !== null) {
            $references = $this->learning->cellReviewReferences([$organizationId, $projectId, $sessionId], $snapshot, $decisions, $reviewContexts);
            $references = array_values(array_filter($references,
                static fn (mixed $reference): bool => EstimateComposerInput::sourceClaimReference($reference, $facts, $input->claimAnswerFactIds)));
            $input = $input->withSourceClaimReferences(array_slice([...$input->sourceClaimReferences, ...$references], 0, EstimateComposerInput::MAX_SOURCE_CLAIMS));
        }

        if ($this->cellSources !== null && in_array('equipment', $input->allowedWorkSections, true)) {
            $byId = $reviewContexts === [] ? [] : array_column($snapshot->facts, null, 'id');
            $reviewedRows = [];
            foreach ($reviewContexts as $reviewContext) {
                $review = $reviewContext === null ? null : ($byId[$reviewContext['identity']['review']['fact_id']] ?? null);
                if (is_string($review?->value['proposal']['row_id'] ?? null)) {
                    $reviewedRows[] = $review->value['proposal']['row_id'];
                }
            }
            $rows = $this->cellSources->capture($organizationId, $projectId, $sessionId, $reviewedRows);
            if ($rows !== []) {
                $input = $input->withNativeCellContext(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource::wire($rows));
            }
        }

        return $input;
    }

    public function carryWorkTechnologyContexts(EstimateComposerInput $input, array $locals, array $answerPolicy): array
    {
        $hasQuestion = $input->workTechnologyContext !== [];
        foreach ($locals as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $work) {
                    $hasQuestion = $hasQuestion || isset($work['composition_intent']['technology_clarification']);
                }
            }
        }
        if (! $hasQuestion) {
            return $locals;
        }
        $capture = $this->models->snapshotForPlanning($input->organizationId, $input->projectId, $input->sessionId, $this->maxFacts + 1);
        $policy = ResolveCurrentEstimateClarification::composerPolicy($answerPolicy);
        if ($policy === null || ! hash_equals($input->snapshotToken, (string) $capture['token'])) {
            throw new InvalidArgumentException('estimate_composer_snapshot_changed');
        }

        return ResolveCurrentEstimateClarification::bindWorkTechnologyContexts($locals, $capture['snapshot'],
            [$input->organizationId, $input->projectId, $input->sessionId], $policy, $input->workTechnologyContext);
    }

    private function directQuantityBindings(ProjectModelSnapshot $snapshot, array $decisions, array $quantities, string $token, array $eligibleFactIds): array
    {
        $facts = array_column($snapshot->facts, null, 'id');
        $choices = array_column($decisions, null, 'id');
        $eligible = array_fill_keys($eligibleFactIds, true);
        $bindings = [];
        foreach ($quantities as $quantity) {
            if (($quantity['formula_key'] ?? null) !== 'direct_quantity') {
                continue;
            }
            $basis = $quantity['quantity_basis'];
            $formula = $basis['formula_inputs'] ?? [];
            $operands = $formula['operands'] ?? [];
            $proof = $operands[0]['quantity_binding'] ?? [];
            $binding = $facts[$proof['fact_id'] ?? ''] ?? null;
            $decision = $choices[$proof['decision_id'] ?? ''] ?? null;
            if (! $binding instanceof Fact || ! $decision instanceof Decision || count($operands) !== 1
                || ! isset($eligible[$binding->id]) || ($basis['model_version'] ?? null) !== $token
                || ($formula['snapshot_identity']['input_fingerprint'] ?? null) !== $token) {
                continue;
            }
            $source = (new DirectQuantityBinding)->source($snapshot, $binding, $decision);
            if ($source === null || ! isset($eligible[$source->id]) || ($quantity['operand_fact_ids'] ?? []) !== [$source->id]
                || ($quantity['id'] ?? null) !== $binding->value['quantity_key']
                || $quantity['quantity_key'] !== $quantity['id'] || ($basis['key'] ?? null) !== $quantity['id']) {
                continue;
            }
            $fresh = (new DerivedQuantityFactory)->derive($snapshot, $decisions, [
                'quantity_id' => $quantity['id'], 'formula_identity' => 'direct_quantity', 'formula_version' => '1',
                'entity_id' => $source->entityId, 'operands' => ['measurement' => $source->id],
                'quantity_binding_fact_id' => $binding->id, 'quantity_binding_decision_id' => $decision->id,
                'output_unit' => DirectQuantityBinding::canonicalUnit($source->unit), 'rounding_mode' => 'half_up',
                'rounding_scale' => 8, 'snapshot' => $formula['snapshot_identity'],
            ]);
            $current = $fresh->quantity;
            $expectedSource = $source->origin === 'user_assumption' ? 'estimated' : 'evidenced';
            $expectedAssumptions = $source->origin === 'user_assumption' ? [$source->id] : [];
            $evidence = $basis['evidence_ids'] ?? [];
            $currentEvidence = $current?->evidenceIds ?? [];
            if (! is_array($evidence) || ! array_is_list($evidence)) {
                continue;
            }
            sort($evidence, SORT_STRING);
            sort($currentEvidence, SORT_STRING);
            if (! $fresh->isReady() || $current === null || $quantity['entity_id'] !== $current->entityId
                || ($formula['entity_id'] ?? null) !== $current->entityId
                || $quantity['value'] !== $current->value || ($basis['amount'] ?? null) !== $current->value
                || $quantity['unit'] !== $current->unit || ($basis['unit'] ?? null) !== $current->unit
                || ($basis['formula_key'] ?? null) !== 'direct_quantity' || ($basis['formula_version'] ?? null) !== '1'
                || $quantity['source'] !== $expectedSource || ($basis['source'] ?? null) !== $expectedSource
                || ($basis['assumptions'] ?? []) !== $expectedAssumptions
                || $quantity['review_blockers'] !== [] || ($basis['review_blockers'] ?? []) !== []
                || $evidence !== $currentEvidence
                || CanonicalPipelineJson::encode($operands) !== CanonicalPipelineJson::encode($current->operands)) {
                continue;
            }
            $bindings[$quantity['id']] = ['work_item_key' => $binding->value['work_item_key'], 'entity_id' => $source->entityId,
                'binding_fact_id' => $binding->id, 'decision_id' => $decision->id,
                'quantity_hash' => hash('sha256', CanonicalPipelineJson::encode($quantity))];
        }

        return $bindings;
    }

    private function composerAnswerRelatedEntities(Fact $answer, array $context, array $facts, array $eligibleFacts, array $entities, array $evidence): array
    {
        $related = [];
        foreach ($answer->value['composer_context']['dependencies'] as $dependency) {
            $source = $facts[$dependency['fact_id']] ?? null;
            if (isset($context['target_entity_id']) && $source?->entityId !== $context['target_entity_id']) {
                continue;
            }
            if (! $source instanceof Fact || $source->origin !== 'document' || $source->status !== 'confirmed') {
                continue;
            }
            $entity = $entities[$source->entityId] ?? null;
            if ($entity === null) {
                continue;
            }
            foreach ($source->evidenceIds as $id) {
                if (($evidence[$id] ?? null)?->sourceType !== 'document') {
                    return [];
                }
            }
            $related[$source->entityId] ??= [];
            if (count($related) > 1) {
                return [];
            }
            $type = $entity->attributes['semantic_type'] ?? $entity->attributes['properties']['semantic_type'] ?? $entity->type;
            if (isset($eligibleFacts[$source->id]) && $source->unit === null && is_string($source->value) && trim($source->value) !== ''
                && ($domain = FactVocabulary::documentDomain($source->type)) !== null
                && is_string($type) && FactVocabulary::documentSupportsEntity($source->type, $type)) {
                $related[$source->entityId][$domain] = true;
            }
        }
        $entityId = array_key_first($related);

        return $entityId !== null && array_intersect(array_keys($related[$entityId]), $context['work_sections']) !== [] ? [$entityId] : [];
    }

    /** @param array<string, mixed> $quantity @return array<string, mixed> */
    private function derivedQuantity(array $quantity): array
    {
        $id = $quantity['id'] ?? $quantity['key'] ?? null;
        $value = $quantity['value'] ?? $quantity['amount'] ?? null;
        $unit = $quantity['unit'] ?? null;
        if (! is_string($id) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/D', $id) !== 1
            || (! is_int($value) && ! is_float($value) && ! is_string($value))
            || ! is_numeric($value) || (float) $value <= 0
            || ! is_string($unit) || trim($unit) === '' || strlen($unit) > 32) {
            throw new InvalidArgumentException('estimate_composer_derived_quantity_invalid');
        }

        $formula = is_array($quantity['formula_inputs'] ?? null) ? $quantity['formula_inputs'] : [];
        $entityId = $formula['entity_id'] ?? null;
        $factIds = [];
        foreach (is_array($formula['operands'] ?? null) ? $formula['operands'] : [] as $operand) {
            if (is_array($operand) && is_string($operand['fact_id'] ?? null)) {
                $factIds[] = $operand['fact_id'];
            }
        }
        $factIds = array_values(array_unique($factIds));
        sort($factIds, SORT_STRING);

        return ['id' => $id, 'value' => (string) $value, 'unit' => $unit,
            'quantity_key' => is_string($quantity['key'] ?? null) ? $quantity['key'] : $id,
            'formula_key' => is_string($quantity['formula_key'] ?? null) ? $quantity['formula_key'] : null,
            'source' => is_string($quantity['source'] ?? null) ? $quantity['source'] : 'unknown',
            'review_blockers' => is_array($quantity['review_blockers'] ?? null) ? $quantity['review_blockers'] : [],
            'quantity_basis' => array_intersect_key($quantity, array_flip([
                'key', 'unit', 'amount', 'formula_key', 'formula_version', 'formula_inputs',
                'source', 'evidence_ids', 'model_version', 'assumptions', 'review_blockers',
            ])),
            ...(is_string($entityId) ? ['entity_id' => $entityId] : []),
            ...($factIds === [] ? [] : ['operand_fact_ids' => $factIds])];
    }

    private function sourceDocumentId(Fact $fact, array $evidence): ?int
    {
        if ($fact->origin !== 'document' || $fact->status !== 'confirmed' || $fact->type !== 'equipment_specification'
            || $fact->unit !== null || $fact->evidenceIds === []) {
            return null;
        }
        $documents = [];
        foreach ($fact->evidenceIds as $id) {
            $proof = $evidence[$id] ?? null;
            if ($proof === null || $proof->sourceType !== 'document' || $proof->sourceVersion !== $fact->sourceVersion
                || [$proof->organizationId, $proof->projectId, $proof->sessionId] !== [$fact->organizationId, $fact->projectId, $fact->sessionId]
                || preg_match('/\Adocument:([1-9][0-9]*)\z/D', $proof->sourceReference ?? $proof->sourceArtifactId, $match) !== 1
                || (string) (int) $match[1] !== $match[1]
                || (preg_match('/\Adocument:([1-9][0-9]*)\z/D', $proof->sourceArtifactId, $artifact) === 1 && $artifact[1] !== $match[1])) {
                return null;
            }
            $documents[(int) $match[1]] = true;
        }

        return count($documents) === 1 ? array_key_first($documents) : null;
    }

    /** @return array<string, mixed> */
    private function fact(Fact $fact): array
    {
        return [
            'id' => $fact->id,
            'source_version' => $fact->sourceVersion,
            'entity_id' => $fact->entityId,
            'type' => $fact->type,
            'value' => $fact->value,
            'unit' => $fact->unit,
            'origin' => $fact->origin,
            'status' => $fact->status,
            'evidence_ids' => $fact->evidenceIds,
            'version' => $fact->version,
        ];
    }

    /** @return array<string, mixed> */
    private function decision(Decision $decision): array
    {
        return [
            'id' => $decision->id,
            'source_version' => $decision->sourceVersion,
            'target_type' => $decision->targetType,
            'target_id' => $decision->targetId,
            'selected_fact_id' => $decision->selectedFactId,
            'actor_type' => $decision->actorType,
            'reason' => $decision->reason,
            'version' => $decision->version,
            'evidence_ids' => $decision->evidenceIds,
        ];
    }
}

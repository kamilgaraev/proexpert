<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Questions\CurrentEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class UserEvaluationChoiceWriter
{
    public function __construct(private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models, private EvidenceRepository $evidence,
        private ?\App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService $sourceClaims = null) {}

    public function write(User $actor, EstimateGenerationSession $session, string $anchorKey, string $type, array $value,
        string $reason, string $requestId): array
    {
        $this->authorization->authorize($actor, $session, 'estimate_generation.review');
        if (! Str::isUuid($requestId) || ! in_array($type, ['evaluation_price', 'evaluation_scope', 'evaluation_technology', 'evaluation_scenario', 'evaluation_answer', 'evaluation_currency', 'evaluation_contingency'], true)
            || $anchorKey === '' || strlen($anchorKey) > 191 || trim($reason) === '' || strlen($reason) > 1000) {
            throw new InvalidArgumentException('evaluation_choice_contract_invalid');
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $version = 'sha256:'.hash('sha256', 'evaluation-choices:v1|'.implode('|', $scope));
        $entityId = 'entity:'.hash('sha256', $version.'|'.$anchorKey);
        // This is a ledger anchor for a choice. It has no geometric value or dimensions.
        $entity = new Entity($entityId, ...[...$scope, $version, 'quantity', $entityId,
            ['semantic_type' => 'quantity', 'identity' => ['evaluation_anchor' => $anchorKey]]]);
        $previous = null;
        foreach ($this->models->currentFacts(...[...$scope, $entityId]) as $fact) {
            if ($fact->type === $type && $fact->sourceVersion === $version) {
                $previous = $fact;
            }
        }
        $factId = 'fact:'.hash('sha256', $requestId.'|'.$entityId.'|'.$type);
        $decisionId = 'decision:'.hash('sha256', $requestId.'|'.$factId);
        $value = [...$value, 'decision_id' => $decisionId, 'reason' => $reason];
        $hash = hash('sha256', CanonicalPipelineJson::encode($value));

        return $this->evidence->transaction($scope[0], $scope[2], function () use ($scope, $version, $entity, $entityId, $value, $hash,
            $factId, $decisionId, $type, $actor, $reason, $previous): array {
            $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
                'input:'.$scope[2], $version, ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => 'input_payload.evaluation_choices'],
                ['fact_key' => 'evaluation_choice', 'fact_value' => $hash], 0.0, EvidenceProducer::UserInputNormalizer->value,
                'sha256:'.hash('sha256', 'evaluation-choices:v1')]));
            $proofId = 'evidence:'.$node->id;
            $proof = new Evidence($proofId, ...[...$scope, $version, 'input:'.$scope[2], 'user_input', null, null, 'input_payload.evaluation_choices',
                EvidenceProducer::UserInputNormalizer->value, 'input:'.$scope[2], $hash]);
            $fact = new Fact($factId, ...[...$scope, $version, $entityId, $type, $value, null, 0.0, 'user_input', 'confirmed', [$proofId],
                ($previous?->version ?? 0) + 1, $previous?->id]);
            $decision = new Decision($decisionId, ...[...$scope, $version, 'fact', $previous?->id ?? $factId, $factId, 'user',
                (string) $actor->id, $reason, $fact->version, [$proofId]]);
            $this->models->saveSourceModel([$entity], [$fact], [$proof]);
            $this->models->applyDecision($decision, $fact);

            return ['fact_id' => $factId, 'decision_id' => $decisionId, 'entity_id' => $entityId];
        });
    }

    public function writeComposerAnswer(User $actor, EstimateGenerationSession $session, CurrentEstimateClarification $current,
        string $response, ?string $other, string $requestId, string $decisionId, ?string $targetEntityId = null, array $documentIds = [],
        ?array $parameter = null): Decision
    {
        $this->authorization->authorize($actor, $session, 'estimate_generation.review');
        $choice = array_column($current->question->choices, null, 'value')[$response] ?? null;
        $workQuestion = ($current->question->sourceLocator['kind'] ?? null) === 'composer_work_technology';
        $parameter = \App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification::workParameterInput($parameter);
        if ($parameter !== null) {
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            if (! $workQuestion || $response !== 'other' || $this->sourceClaims === null) {
                throw new InvalidArgumentException('estimate_generation.question_response_invalid');
            }
        }
        $revoke = $workQuestion && $response === 'revoke' && ($current->workAnswer['response'] ?? null) === 'other';
        if ($current->composerContext === null || $current->targetFactId !== null
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $requestId) !== 1
            || (! $revoke && ($choice === null || $choice->kind !== $response))
            || ($current->composerContext['state_version'] ?? null) !== (int) $session->state_version
            || (! $revoke && ! in_array($response, ['other', 'leave_unresolved'], true))
            || ($workQuestion && ($targetEntityId !== null || $session->status !== \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus::EstimateReviewRequired))
            || ($documentIds !== [] && (! $workQuestion || $response !== 'other'))
            || ($targetEntityId !== null && ($response !== 'other'
                || ! in_array($targetEntityId, array_column($current->targetOptions, 'entity_id'), true)))
            || ($response === 'other' ? ! is_string($other) || trim($other) === '' || mb_strlen($other) > 500 : $other !== null)) {
            throw new InvalidArgumentException('estimate_generation.question_response_invalid');
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $version = $current->sourceVersion;
        $anchor = 'composer-answer:v1|'.implode('|', $scope).'|'.$current->question->code;
        $entityId = 'entity:'.hash('sha256', $anchor);
        $factId = 'fact:'.hash('sha256', $requestId.'|'.$anchor);
        $reason = $current->question->subject;
        $documents = [];
        if ($workQuestion) {
            $documents = \App\BusinessModules\Addons\EstimateGeneration\Questions\EloquentEstimateClarificationSource::currentDocuments(...[...$scope, true, $documentIds]);
            if (array_column($documents, 'document_id') !== $documentIds
                || array_filter($documents, static fn (array $document): bool => ! in_array($document, $current->composerContext['documents'] ?? [], true)) !== []) {
                throw new InvalidArgumentException('estimate_generation.question_stale');
            }
        }
        $parameterValue = null;
        $parameterSnapshot = null;
        if ($parameter !== null) {
            $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
            if (count($capture['snapshot']->facts) > 10000 || ! hash_equals($current->snapshotToken, $capture['token'])) {
                throw new InvalidArgumentException('estimate_generation.question_stale');
            }
            $cache = [];
            $requirementOnly = ($parameter['schema_version'] ?? null) === 2;
            $sources = $requirementOnly
                ? $this->sourceClaims->requirementSources($scope, $capture['snapshot'], $current->composerContext['work_context'], $documents, $cache)
                : $this->sourceClaims->parameterOperands($scope, $capture['snapshot'], $current->composerContext['work_context'], $documents, $cache);
            $descriptor = $sources[$parameter[$requirementOnly ? 'source_requirement_ref' : 'source_operand_ref']] ?? null;
            $target = array_column($capture['snapshot']->entities, null, 'id')[$current->composerContext['work_context']['binding']['entity_id']] ?? null;
            if ($descriptor === null || $target === null) {
                throw new InvalidArgumentException('estimate_generation.question_stale');
            }
            $parameterSnapshot = $requirementOnly ? null : $capture['snapshot'];
            $parameterValue = ['input' => $parameter, 'association' => $descriptor,
                'binding' => ['entity_id' => $target->id, 'entity_source_version' => $target->sourceVersion,
                    'work_key' => $current->composerContext['work_context']['binding']['work_key'],
                    'work_binding_hash' => $current->composerContext['work_context']['binding_hash']],
                ...($requirementOnly ? [] : ['observed_fact_id' => 'fact:'.hash('sha256', $factId.'|observed_value'),
                    'requirement_fact_id' => 'fact:'.hash('sha256', $factId.'|minimum_length_requirement')])];
        }
        $value = ['kind' => 'composer_answer', 'numeric_parameters_confirmed' => false,
            'question_key' => $current->question->code, 'response' => $response, 'choice_value' => $response,
            'choice_label' => $choice?->label,
            'other' => $other, 'question_fingerprint' => $current->answerFingerprint,
            'source_locator' => $current->question->sourceLocator, 'composer_context' => $current->composerContext,
            'decision_id' => $decisionId, 'reason' => $reason,
            ...($targetEntityId === null ? [] : ['target_entity_id' => $targetEntityId]),
            ...($workQuestion ? ['documents' => $documents] : []),
            ...($parameterValue === null ? [] : ['parameter' => $parameterValue])];
        $hash = hash('sha256', CanonicalPipelineJson::encode($value));

        return $this->evidence->transaction($scope[0], $scope[2], function () use ($scope, $version, $entityId, $factId, $reason,
            $value, $hash, $decisionId, $actor, $response, $workQuestion, $current, $parameterSnapshot): Decision {
            $previous = $this->models->currentFacts(...[...$scope, $entityId]);
            if ((! $workQuestion && $previous !== []) || ($workQuestion && (count($previous) > 1
                || ($previous[0]->id ?? null) !== ($current->composerContext['previous_fact_id'] ?? null)))) {
                throw new InvalidArgumentException('estimate_generation.question_stale');
            }
            $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
                'input:'.$scope[2], $version, ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => 'input_payload.composer_answer'],
                ['fact_key' => 'evaluation_choice', 'fact_value' => $hash], 0.0, EvidenceProducer::UserInputNormalizer->value,
                'sha256:'.hash('sha256', 'composer-answer:v1')]));
            $proofId = 'evidence:'.$node->id;
            $entity = new Entity($entityId, ...[...$scope, $version, 'quantity', $entityId,
                ['semantic_type' => 'quantity', 'identity' => ['evaluation_anchor' => $value['question_key']]]]);
            $proof = new Evidence($proofId, ...[...$scope, $version, 'input:'.$scope[2], 'user_input', null, null,
                'input_payload.composer_answer', EvidenceProducer::UserInputNormalizer->value, 'input:'.$scope[2], $hash]);
            $fact = new Fact($factId, ...[...$scope, $version, $entityId, 'evaluation_answer', $value, null, 0.0,
                'user_assumption', $response === 'other' ? 'confirmed' : 'unresolved', [$proofId],
                $workQuestion ? ($previous[0]->version ?? 0) + 1 : 1, $workQuestion ? ($previous[0]->id ?? null) : null]);
            $decision = new Decision($decisionId, ...[...$scope, $version, 'fact', $factId, $factId,
                'user', (string) $actor->id, $reason, $fact->version, [$proofId]]);
            $this->models->saveSourceModel([$entity], [$fact], [$proof]);
            if ($parameterSnapshot !== null) {
                $this->writeParameterFacts($scope, $value['parameter'], $parameterSnapshot, $proof);
            }
            $this->models->applyDecision($decision, $fact);

            return $decision;
        });
    }

    private function writeParameterFacts(array $scope, array $parameter,
        \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot $snapshot, Evidence $humanProof): void
    {
        $source = $parameter['association'];
        $rawProof = array_column($snapshot->evidence, null, 'id')[$source['evidence_id']] ?? null;
        if (! $rawProof instanceof Evidence) {
            throw new InvalidArgumentException('estimate_generation.question_stale');
        }
        $definition = ['evidence_id' => $rawProof->id, 'source_version' => $rawProof->sourceVersion,
            'evidence_fingerprint' => $rawProof->fingerprint];
        foreach (['observed_value', 'minimum_length_requirement'] as $role) {
            $attributes = FactVocabulary::engineeringParameterAttributes(...[...$scope, $parameter['binding'], $parameter['input']['name'], $role, $definition]);
            $type = FactVocabulary::engineeringParameterType($attributes);
            $entityId = $role === 'observed_value' ? $parameter['binding']['entity_id'] : $source['raw_entity_id'];
            $version = $role === 'observed_value' ? $parameter['binding']['entity_source_version'] : $source['document_source_version'];
            $previous = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->entityId === $entityId
                && $fact->sourceVersion === $version && $fact->type === $type));
            if (count($previous) > 1) {
                throw new InvalidArgumentException('estimate_generation.question_stale');
            }
            $fact = new Fact($parameter[$role === 'observed_value' ? 'observed_fact_id' : 'requirement_fact_id'],
                ...[...$scope, $version, $entityId, $type,
                    $role === 'observed_value' ? $parameter['input']['value'] : $source['value'],
                    $role === 'observed_value' ? $parameter['input']['unit'] : $source['unit'], 0.0, 'user_assumption', 'unresolved',
                    [$humanProof->id, $rawProof->id], ($previous[0]->version ?? 0) + 1, $previous[0]->id ?? null, [], $attributes]);
            $this->models->saveSourceModel([], [$fact], [$humanProof, $rawProof]);
        }
    }
}

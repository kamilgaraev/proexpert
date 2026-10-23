<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DocumentRequirementAcceptance;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationSelectionVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkScope;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Closure;
use InvalidArgumentException;

use function trans_message;

final readonly class ResolveCurrentEstimateClarification
{
    private const MAX_COMPOSER_DEPENDENCIES = 200;

    public static function workParameterInput(?array $parameter): ?array
    {
        if ($parameter === null) {
            return null;
        }
        $keys = ['name', 'value', 'unit', 'basis', 'source_operand_ref', 'condition'];
        if (count($parameter) !== count($keys) || array_diff(array_keys($parameter), $keys) !== []
            || count(array_filter($parameter, is_string(...))) !== count($keys)
            || trim($parameter['name']) !== $parameter['name'] || $parameter['name'] === '' || mb_strlen($parameter['name']) > 160
            || preg_match('/[\x00-\x1F\x7F]/u', $parameter['name']) !== 0
            || ! in_array($parameter['basis'], ['input', 'measurement', 'assumption'], true)
            || $parameter['condition'] !== 'minimum_length'
            || preg_match('/\A[a-f0-9]{64}\z/D', $parameter['source_operand_ref']) !== 1) {
            throw new InvalidArgumentException('estimate_generation.question_response_invalid');
        }
        try {
            FactVocabulary::meetsMinimumLength($parameter['value'], $parameter['unit'], $parameter['value'], $parameter['unit']);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('estimate_generation.question_response_invalid');
        }

        return array_intersect_key($parameter, array_flip($keys));
    }

    private Closure $translate;

    public function __construct(private ProjectUnderstandingQuestionProjector $projector, ?Closure $translate = null)
    {
        $this->translate = $translate ?? static fn (string $key): string => trans_message($key);
    }

    public static function bindWorkTechnologyContexts(array $locals, ProjectModelSnapshot $snapshot, array $scope, array $policy, array $prior): array
    {
        $previous = [];
        foreach ($prior as $entry) {
            $previous[$entry['context']['binding_hash']] = $entry['context'];
        }
        foreach ($locals as &$local) {
            foreach ($local['sections'] as &$section) {
                foreach ($section['work_items'] as &$work) {
                    $binding = self::workTechnologyBinding($work);
                    if ($binding === null || ! in_array($binding['work_section'], $policy['mode'] === 'legacy'
                        ? \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput::WORK_SECTIONS : $policy['selected_sections'], true)) {
                        continue;
                    }
                    $hash = hash('sha256', CanonicalPipelineJson::encode($binding));
                    $context = $previous[$hash] ?? null;
                    $question = $work['composition_intent']['technology_clarification'] ?? null;
                    if ($context === null && is_array($question)) {
                        $dependencies = self::dependencies($snapshot, $binding['source_fact_ids'], $scope);
                        if ($dependencies === null || array_filter($dependencies, static fn (array $fact): bool => $fact['status'] !== 'confirmed') !== []) {
                            continue;
                        }
                        $context = ['schema_version' => 1, 'binding' => $binding, 'binding_hash' => $hash,
                            'policy' => $policy, 'dependencies' => $dependencies, 'question' => $question];
                    }
                    if ($context !== null) {
                        $work['metadata']['work_technology_context'] = $context;
                    }
                }
                unset($work);
            }
            unset($section);
        }
        unset($local);

        return $locals;
    }

    private static function workTechnologyBinding(array $work): ?array
    {
        $intent = $work['composition_intent'] ?? null;
        if (! is_array($intent) || ($intent['kind'] ?? null) !== 'supplementary'
            || ! is_string($intent['entity_id'] ?? null) || ! is_string($intent['work_section'] ?? null)
            || ($work['work_key'] ?? null) !== ($intent['work_key'] ?? null)
            || ($work['name'] ?? null) !== ($intent['name'] ?? null)
            || ($work['metadata']['scope_entity_id'] ?? null) !== $intent['entity_id']
            || ! is_array($intent['source_fact_ids'] ?? null) || $intent['source_fact_ids'] === []) {
            return null;
        }
        $ids = array_values(array_unique($intent['source_fact_ids']));
        sort($ids, SORT_STRING);

        return ['work_key' => $intent['work_key'], 'name' => $intent['name'], 'entity_id' => $intent['entity_id'],
            'work_section' => $intent['work_section'], 'unit' => $intent['unit'] ?? null,
            'requirement_key' => $intent['requirement_key'] ?? null,
            'technology_package_candidate' => $intent['technology_package_candidate'] ?? null, 'source_fact_ids' => $ids];
    }

    public function workTechnologyContexts(array $draft, array $input, ProjectModelSnapshot $snapshot, array $decisions, array $scope, array $documents,
        ?string $localEstimateKey = null): array
    {
        $policy = self::composerPolicy($input);
        if ($policy === null || ! (new BuildMostEstimateDraft)->verifyArtifact($draft)
            || [$draft['scope_identity']['organization_id'] ?? null, $draft['scope_identity']['project_id'] ?? null,
                $draft['scope_identity']['session_id'] ?? null] !== $scope) {
            return [];
        }
        $selection = $input['scope_selection'] ?? $draft['evaluation_policy']['scope_selection'] ?? null;
        $workSelection = $selection['work_scope'] ?? null;
        $selectedKeys = null;
        if ($workSelection !== null) {
            $expected = ['selected_sections' => $policy['selected_sections'], 'profile_id' => $policy['profile_id'],
                'construction_type' => $input['construction_type'] ?? 'new_construction',
                'price_policy' => $input['price_policy'] ?? 'normative', 'profile_version' => ScopeCompletenessEvaluator::VERSION,
                'work_scope' => $workSelection];
            if (! (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $selection, 'evaluation_scope', $expected)
                || ! (new EvaluationWorkScope)->current($draft, $workSelection)) {
                return [];
            }
            $selectedKeys = array_fill_keys(array_column($workSelection['selected_items'], 'key'), true);
        }
        $locals = $draft['local_estimates'] ?? [];
        if ($localEstimateKey !== null) {
            if (trim($localEstimateKey) === '' || strlen($localEstimateKey) > 255) {
                return [];
            }
            $locals = array_values(array_filter($locals, static fn (mixed $local): bool => is_array($local)
                && ($local['key'] ?? null) === $localEstimateKey));
            if (count($locals) !== 1) {
                return [];
            }
        }
        $result = [];
        $duplicates = [];
        $sourceFacts = array_column($snapshot->facts, null, 'id');
        $checkedAnswers = [];
        foreach ($locals as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $work) {
                    if ($selectedKeys !== null && ! isset($selectedKeys[$work['key'] ?? ''])) {
                        continue;
                    }
                    $context = $work['metadata']['work_technology_context'] ?? null;
                    $binding = self::workTechnologyBinding($work);
                    if (! is_array($context) || $binding === null || ($context['schema_version'] ?? null) !== 1
                        || ! self::sameWorkContext($context['binding'] ?? null, $binding) || ! self::sameWorkContext($context['policy'] ?? null, $policy)
                        || ! in_array($binding['work_section'], $policy['mode'] === 'legacy'
                            ? \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput::WORK_SECTIONS : $policy['selected_sections'], true)
                        || ($context['binding_hash'] ?? null) !== hash('sha256', CanonicalPipelineJson::encode($binding))
                        || ! self::sameWorkContext($context['dependencies'] ?? null, self::dependencies($snapshot, $binding['source_fact_ids'], $scope))
                        || ! is_array($context['question'] ?? null)) {
                        continue;
                    }
                    $key = self::workTechnologyCode($context);
                    if (isset($result[$key])) {
                        $duplicates[$key] = true;
                        continue;
                    }
                    foreach ($binding['source_fact_ids'] as $id) {
                        $source = $sourceFacts[$id] ?? null;
                        if ($source?->type === 'evaluation_answer' && is_array($source->value) && ($source->value['kind'] ?? null) === 'composer_answer'
                            && self::composerAnswerContext($source, $snapshot, $decisions, $input, $checkedAnswers) === null) {
                            continue 2;
                        }
                    }
                    $answer = $this->workTechnologyAnswer($context, $snapshot, $decisions, $scope, $documents);
                    $previous = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'evaluation_answer'
                        && $fact->origin === 'user_assumption' && is_array($fact->value) && ($fact->value['question_key'] ?? null) === $key
                        && ($fact->value['composer_context']['kind'] ?? null) === 'work_technology'
                        && self::sameWorkContext($fact->value['composer_context']['work_context'] ?? null, $context)));
                    $result[$key] = ['context' => $context, 'answer' => $answer, 'documents' => $documents,
                        'previous_fact_id' => count($previous) === 1 ? $previous[0]->id : null,
                        'status' => $answer === null ? 'unanswered' : match ($answer['response']) {
                            'other' => 'answered', 'revoke' => 'revoked', default => 'left_unresolved',
                        }];
                }
            }
        }
        if (count($result) > 200) {
            throw new InvalidArgumentException('estimate_composer_entity_context_too_large');
        }
        ksort($result, SORT_STRING);

        return array_values(array_diff_key($result, $duplicates));
    }

    public static function workTechnologyCode(array $context): string
    {
        return 'composer_work_technology_'.substr(hash('sha256', CanonicalPipelineJson::encode($context)), 0, 40);
    }

    private static function sameWorkContext(mixed $left, mixed $right): bool
    {
        return is_array($left) && is_array($right) && CanonicalPipelineJson::encode($left) === CanonicalPipelineJson::encode($right);
    }

    private function workTechnologyAnswer(array $context, ProjectModelSnapshot $snapshot, array $decisions, array $scope, array $documents): ?array
    {
        $code = self::workTechnologyCode($context);
        $matches = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'evaluation_answer'
            && is_array($fact->value) && ($fact->value['question_key'] ?? null) === $code));
        if (count($matches) !== 1) {
            return null;
        }
        $fact = $matches[0];
        $value = $fact->value;
        if ([$fact->organizationId, $fact->projectId, $fact->sessionId] !== $scope || $fact->origin !== 'user_assumption'
            || ! in_array($fact->status, ['confirmed', 'unresolved'], true) || $fact->unit !== null
            || ($value['kind'] ?? null) !== 'composer_answer' || ($value['numeric_parameters_confirmed'] ?? null) !== false
            || ($value['composer_context']['schema_version'] ?? null) !== 2 || ($value['composer_context']['kind'] ?? null) !== 'work_technology'
            || ! self::sameWorkContext($value['composer_context']['work_context'] ?? null, $context)
            || $fact->sourceVersion !== 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($context))
            || ! in_array($value['response'] ?? null, ['other', 'leave_unresolved', 'revoke'], true)
            || ($value['response'] === 'other' && ($fact->status !== 'confirmed' || ! is_string($value['other'] ?? null) || trim($value['other']) === '' || mb_strlen($value['other']) > 500))
            || ($value['response'] !== 'other' && ($fact->status !== 'unresolved' || ($value['other'] ?? null) !== null))
            || ! is_array($value['documents'] ?? null)) {
            return null;
        }
        $selected = array_values(array_filter($decisions, static fn (Decision $decision): bool => $decision->selectedFactId === $fact->id
            && $decision->actorType === 'user' && [$decision->organizationId, $decision->projectId, $decision->sessionId] === $scope
            && $decision->id === ($value['decision_id'] ?? null) && $decision->sourceVersion === $fact->sourceVersion
            && $decision->version === $fact->version && $decision->reason === $context['question']['question']));
        if (count($selected) !== 1 || $fact->evidenceIds === []) {
            return null;
        }
        $proofs = array_column($snapshot->evidence, null, 'id');
        $hash = hash('sha256', CanonicalPipelineJson::encode($value));
        foreach ($fact->evidenceIds as $id) {
            $proof = $proofs[$id] ?? null;
            if ($proof === null || [$proof->organizationId, $proof->projectId, $proof->sessionId] !== $scope
                || $proof->sourceType !== 'user_input' || $proof->nativeReference !== 'input_payload.composer_answer'
                || $proof->sourceVersion !== $fact->sourceVersion || $proof->sourceArtifactId !== 'input:'.$scope[2]
                || $proof->sourceReference !== 'input:'.$scope[2] || $proof->producerName !== 'user_input_normalizer' || $proof->choiceHash !== $hash) {
                return null;
            }
        }
        foreach ($value['documents'] as $document) {
            if (! in_array(CanonicalPipelineJson::encode($document), array_map(CanonicalPipelineJson::encode(...), $documents), true)) {
                return null;
            }
        }

        $parameter = null;
        if ($value['response'] === 'other' && is_array($value['parameter'] ?? null)) {
            try {
                $input = self::workParameterInput($value['parameter']['input'] ?? []);
                $observed = $value['parameter']['observed_fact_id'] ?? null;
                $requirement = $value['parameter']['requirement_fact_id'] ?? null;
                if ($input !== null && is_string($observed) && is_string($requirement)) {
                    $parameter = ['input' => $input, 'observed_fact_id' => $observed, 'requirement_fact_id' => $requirement,
                        'interpretation_status' => 'user_assumption', 'engineering_applicability' => 'unresolved',
                        'numeric_parameters_confirmed' => false];
                }
            } catch (InvalidArgumentException) {
                $parameter = null;
            }
        }

        return ['response' => $value['response'], 'other' => $value['other'] ?? null, 'decision_id' => $selected[0]->id,
            'fact_id' => $fact->id, 'document_ids' => array_column($value['documents'], 'document_id'),
            'trust' => $value['response'] === 'other' ? 'user_assumption' : 'no_active_claim',
            'engineering_applicability' => 'unresolved', 'numeric_parameters_confirmed' => false,
            ...($parameter === null ? [] : ['parameter' => $parameter])];
    }

    public function workTechnologyQuestions(array $entries, string $token, int $stateVersion): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $work = $entry['context'];
            $context = ['schema_version' => 2, 'kind' => 'work_technology', 'work_context' => $work,
                'state_version' => $stateVersion, 'previous_fact_id' => $entry['previous_fact_id'] ?? $entry['answer']['fact_id'] ?? null, 'documents' => $entry['documents']];
            $question = new EstimateClarificationQuestion(self::workTechnologyCode($work), $work['question']['question'],
                $work['question']['reason'], ($this->translate)('estimate_generation.work_technology.impact'),
                ($this->translate)('estimate_generation.work_technology.recommendation'),
                [new EstimateClarificationChoice('other', ($this->translate)('estimate_generation.ai_questions.other'), 'other'),
                    new EstimateClarificationChoice('leave_unresolved', ($this->translate)('estimate_generation.ai_questions.leave_unresolved'), 'leave_unresolved')],
                ['kind' => 'composer_work_technology', 'work_key' => $work['binding']['work_key'], 'entity_id' => $work['binding']['entity_id'],
                    'work_section' => $work['binding']['work_section'], 'definition_hash' => $work['binding_hash']]);
            $result[] = new CurrentEstimateClarification($question, 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($work)), $token,
                hash('sha256', CanonicalPipelineJson::encode([$context, $token, $entry['answer']])), null, $context, [], $entry['answer']);
        }

        return $result;
    }

    public function resolveComposer(array $draft, array $input, ProjectModelSnapshot $snapshot, string $snapshotToken, array $scope, int $stateVersion = 0): ?CurrentEstimateClarification
    {
        $composition = $draft['estimate_composition'] ?? [];
        $policy = self::composerPolicy($input);
        if ($policy === null || ! array_is_list($scope) || count($scope) !== 3
            || count(array_filter($scope, static fn ($id): bool => is_int($id) && $id > 0)) !== 3 || $stateVersion < 0
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1 || ($composition['schema_version'] ?? null) !== 1
            || ($draft['source_info_only'] ?? null) !== true
            || ($draft['is_complete'] ?? null) !== false || ($draft['evaluation_result'] ?? null) !== null
            || ($draft['local_estimates'] ?? null) !== [] || ($draft['stage6_candidate_rows'] ?? []) !== []
            || ($draft['generation_contract'] ?? null) !== ($policy['mode'] === 'universal' ? UniversalDraftProjector::CONTRACT : BuildMostEstimateDraft::CONTRACT)
            || [$draft['scope_identity']['organization_id'] ?? null, $draft['scope_identity']['project_id'] ?? null, $draft['scope_identity']['session_id'] ?? null] !== $scope
            || ! (new BuildMostEstimateDraft)->verifyArtifact($draft)
            || ($composition['snapshot_token'] ?? null) !== $snapshotToken
            || ! is_string($composition['input_fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $composition['input_fingerprint']) !== 1
            || ($draft['evaluation_policy']['mode'] ?? 'legacy') !== $policy['mode']
            || ($draft['evaluation_policy']['profile_id'] ?? 'construction') !== $policy['profile_id']
            || ($draft['evaluation_policy']['selected_sections'] ?? []) !== $policy['selected_sections']) {
            return null;
        }
        $initial = self::initialRequest($snapshot, $policy, $scope);
        if ($initial === null) {
            return null;
        }
        $requests = [];
        $factIds = [$initial->id];
        foreach (is_array($composition['request_info'] ?? null) ? $composition['request_info'] : [] as $request) {
            if (! is_array($request) || ! is_int($request['index'] ?? null) || ! is_string($request['reason'] ?? null)
                || ! array_key_exists('work_name', $request)
                || ($request['work_name'] !== null && (! is_string($request['work_name']) || mb_strlen($request['work_name']) > 300))
                || ! str_starts_with($request['reason'], 'estimate_composer_')
                || in_array($request['reason'], ['estimate_composer_work_section_not_selected', 'estimate_composer_physical_duplicate_covered'], true)
                || ! in_array($request['next_action'] ?? null, ['provide_work_scope', 'confirm_work_source'], true)
                || ! is_array($request['source_fact_ids'] ?? null) || ! array_is_list($request['source_fact_ids'])) {
                continue;
            }
            foreach ($request['source_fact_ids'] as $id) {
                if (! is_string($id)) {
                    return null;
                }
                $factIds[] = $id;
            }
            $requests[] = $request;
        }
        if ($requests === []) {
            return null;
        }
        $factIds = array_values(array_unique($factIds));
        sort($factIds, SORT_STRING);
        $dependencies = self::dependencies($snapshot, $factIds, $scope);
        if ($dependencies === null) {
            return null;
        }
        $indices = array_values(array_unique(array_column($requests, 'index')));
        sort($indices, SORT_NUMERIC);
        $locator = ['kind' => 'composer_request_info', 'artifact_hash' => $draft['artifact_hash'],
            'request_count' => count($requests), 'request_indices' => $indices, 'source_fact_ids' => $factIds];
        $context = ['schema_version' => 1, 'artifact_hash' => $draft['artifact_hash'], 'input_fingerprint' => $composition['input_fingerprint'],
            'state_version' => $stateVersion, 'policy' => $policy, 'initial_fact_id' => $initial->id, 'dependencies' => $dependencies];
        $question = new EstimateClarificationQuestion(
            'composer_source_'.substr(hash('sha256', CanonicalPipelineJson::encode(['scope' => $scope, 'locator' => $locator])), 0, 40),
            ($this->translate)('estimate_generation.composer_clarification.subject'),
            ($this->translate)('estimate_generation.composer_clarification.reason').' '.str_replace(':count', (string) count($requests),
                ($this->translate)('estimate_generation.composer_clarification.summary')),
            ($this->translate)('estimate_generation.composer_clarification.impact'),
            ($this->translate)('estimate_generation.composer_clarification.recommendation'),
            [new EstimateClarificationChoice('other', ($this->translate)('estimate_generation.ai_questions.other'), 'other'),
                new EstimateClarificationChoice('leave_unresolved', ($this->translate)('estimate_generation.ai_questions.leave_unresolved'), 'leave_unresolved')],
            $locator,
        );

        return new CurrentEstimateClarification($question, 'sha256:'.$draft['artifact_hash'], $snapshotToken,
            hash('sha256', CanonicalPipelineJson::encode(['question' => $question->toArray(), 'snapshot_token' => $snapshotToken, 'context' => $context])), null, $context,
            self::composerTargetOptions($snapshot, $factIds, $policy['mode'] === 'legacy'
                ? \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput::WORK_SECTIONS : $policy['selected_sections']));
    }

    private static function composerTargetOptions(ProjectModelSnapshot $snapshot, array $factIds, array $sections): array
    {
        $entities = array_column($snapshot->entities, null, 'id');
        $proofs = array_column($snapshot->evidence, null, 'id');
        $facts = array_column($snapshot->facts, null, 'id');
        $options = [];
        foreach ($factIds as $id) {
            $fact = $facts[$id] ?? null;
            if (! $fact instanceof Fact || $fact->origin !== 'document' || $fact->status !== 'confirmed'
                || $fact->unit !== null || ! is_string($fact->value) || trim($fact->value) === '' || $fact->evidenceIds === []) {
                continue;
            }
            $entity = $entities[$fact->entityId] ?? null;
            $type = $entity?->attributes['semantic_type'] ?? $entity?->attributes['properties']['semantic_type'] ?? $entity?->type;
            if ($entity === null || $entity->sourceVersion !== $fact->sourceVersion || ! is_string($type)
                || ! in_array(FactVocabulary::documentDomain($fact->type), $sections, true)
                || ! FactVocabulary::documentSupportsEntity($fact->type, $type)) {
                continue;
            }
            $locator = '';
            foreach ($fact->evidenceIds as $id) {
                if (($proofs[$id] ?? null)?->sourceType !== 'document') {
                    continue 2;
                }
                $reference = $proofs[$id]->nativeReference;
                if ($locator === '' && is_string($reference) && str_starts_with($reference, 'xlsx:sheet:')) {
                    $locator = mb_substr(substr($reference, strlen('xlsx:sheet:')), 0, 100);
                }
            }
            $options[$entity->id] ??= ['entity_id' => $entity->id,
                'label' => mb_substr(trim($fact->value), 0, $locator === '' ? 300 : 196).($locator === '' ? '' : ' ('.$locator.')')];
        }
        ksort($options, SORT_STRING);

        return array_values($options);
    }

    public static function composerPolicy(array $input): ?array
    {
        $mode = $input['evaluation_mode'] ?? $input['mode'] ?? 'legacy';
        $sections = $mode === 'universal' ? ($input['selected_sections'] ?? []) : [];
        if (! in_array($mode, ['legacy', 'universal'], true) || ! is_array($sections) || ! array_is_list($sections)
            || count(array_filter($sections, 'is_string')) !== count($sections)
            || array_diff($sections, \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput::WORK_SECTIONS) !== []
            || ! is_string($input['profile_id'] ?? 'construction')
            || ! is_string($input['description'] ?? null) || trim($input['description']) === '') {
            return null;
        }

        return ['mode' => $mode, 'profile_id' => $input['profile_id'] ?? 'construction', 'selected_sections' => $sections,
            'description_hash' => hash('sha256', trim($input['description']))];
    }

    public static function composerAnswerContext(Fact $answer, ProjectModelSnapshot $snapshot, array $decisions, array $input, array &$checked = []): ?array
    {
        if (array_key_exists($answer->id, $checked)) {
            return $checked[$answer->id];
        }
        if (count($checked) >= self::MAX_COMPOSER_DEPENDENCIES) {
            return null;
        }
        $checked[$answer->id] = null;
        $checked[$answer->id] = self::checkedComposerAnswerContext($answer, $snapshot, $decisions, $input, $checked);

        return $checked[$answer->id];
    }

    private static function checkedComposerAnswerContext(Fact $answer, ProjectModelSnapshot $snapshot, array $decisions, array $input, array &$checked): ?array
    {
        $value = $answer->value;
        $context = is_array($value) ? ($value['composer_context'] ?? null) : null;
        $policy = self::composerPolicy($input);
        $scope = [$answer->organizationId, $answer->projectId, $answer->sessionId];
        if ($answer->type !== 'evaluation_answer' || $answer->origin !== 'user_assumption' || $answer->status !== 'confirmed'
            || $answer->unit !== null || ! is_array($value) || ($value['kind'] ?? null) !== 'composer_answer'
            || ($value['numeric_parameters_confirmed'] ?? null) !== false || ($value['response'] ?? null) !== 'other'
            || ! is_string($value['other'] ?? null) || trim($value['other']) === '' || mb_strlen($value['other']) > 500
            || ! is_array($context) || ($context['schema_version'] ?? null) !== 1 || $policy === null
            || CanonicalPipelineJson::encode(['policy' => $context['policy'] ?? null]) !== CanonicalPipelineJson::encode(['policy' => $policy]) || ! is_string($context['artifact_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $context['artifact_hash']) !== 1
            || $answer->sourceVersion !== 'sha256:'.$context['artifact_hash']
            || ($value['source_locator']['kind'] ?? null) !== 'composer_request_info'
            || ($value['source_locator']['artifact_hash'] ?? null) !== $context['artifact_hash']
            || ! is_array($context['dependencies'] ?? null) || ! array_is_list($context['dependencies']) || $context['dependencies'] === []) {
            return null;
        }
        $initial = self::initialRequest($snapshot, $policy, $scope);
        $factIds = array_column($context['dependencies'], 'fact_id');
        if ($initial === null || ($context['initial_fact_id'] ?? null) !== $initial->id || ! in_array($initial->id, $factIds, true)
            || in_array($answer->id, $factIds, true) || count($factIds) !== count($context['dependencies'])
            || CanonicalPipelineJson::encode(['dependencies' => self::dependencies($snapshot, $factIds, $scope)]) !== CanonicalPipelineJson::encode(['dependencies' => $context['dependencies']])) {
            return null;
        }
        $current = array_column($snapshot->facts, null, 'id')[$answer->id] ?? null;
        $decision = array_column($decisions, null, 'id')[$value['decision_id'] ?? ''] ?? null;
        if ($current === null || $current->version !== $answer->version || $current->sourceVersion !== $answer->sourceVersion
            || DocumentRequirementAcceptance::valueHash($current) !== DocumentRequirementAcceptance::valueHash($answer)
            || ! $decision instanceof Decision || $decision->actorType !== 'user' || ! ctype_digit($decision->actorId)
            || [$decision->organizationId, $decision->projectId, $decision->sessionId] !== $scope
            || $decision->sourceVersion !== $answer->sourceVersion || $decision->selectedFactId !== $answer->id
            || $decision->targetType !== 'fact' || $decision->targetId !== $answer->id || $decision->version !== $answer->version
            || ($value['reason'] ?? null) !== $decision->reason || $answer->evidenceIds === []) {
            return null;
        }
        $facts = array_column($snapshot->facts, null, 'id');
        foreach ($factIds as $id) {
            $source = $facts[$id];
            if ($source->type === 'evaluation_answer' && is_array($source->value) && ($source->value['kind'] ?? null) === 'composer_answer'
                && self::composerAnswerContext($source, $snapshot, $decisions, $input, $checked) === null) {
                return null;
            }
        }
        $proofs = array_column($snapshot->evidence, null, 'id');
        $hash = hash('sha256', CanonicalPipelineJson::encode($value));
        foreach ($answer->evidenceIds as $id) {
            $proof = $proofs[$id] ?? null;
            if ($proof === null || [$proof->organizationId, $proof->projectId, $proof->sessionId] !== $scope
                || $proof->sourceVersion !== $answer->sourceVersion || $proof->sourceType !== 'user_input'
                || $proof->producerName !== 'user_input_normalizer' || $proof->nativeReference !== 'input_payload.composer_answer'
                || $proof->sourceReference !== 'input:'.$answer->sessionId || $proof->choiceHash !== $hash
                || ! in_array($id, $decision->evidenceIds, true)) {
                return null;
            }
        }

        $sections = $policy['mode'] === 'legacy'
            ? \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput::WORK_SECTIONS : $policy['selected_sections'];
        if (array_key_exists('target_entity_id', $value)
            && (! is_string($value['target_entity_id'])
                || ! in_array($value['target_entity_id'], array_column(self::composerTargetOptions($snapshot, $factIds, $sections), 'entity_id'), true))) {
            return null;
        }

        return ['entity_id' => $initial->entityId, 'work_sections' => $sections,
            ...(array_key_exists('target_entity_id', $value) ? ['target_entity_id' => $value['target_entity_id']] : [])];
    }

    private static function initialRequest(ProjectModelSnapshot $snapshot, array $policy, array $scope): ?Fact
    {
        $evidence = array_column($snapshot->evidence, null, 'id');
        $matches = [];
        foreach ($snapshot->facts as $fact) {
            if ([$fact->organizationId, $fact->projectId, $fact->sessionId] !== $scope || $fact->type !== 'evaluation_answer'
                || $fact->origin !== 'user_input' || $fact->status !== 'confirmed' || ! is_array($fact->value)
                || ($fact->value['kind'] ?? null) !== 'initial_description' || ($fact->value['numeric_parameters_confirmed'] ?? null) !== false
                || ! is_string($fact->value['description'] ?? null) || hash('sha256', trim($fact->value['description'])) !== $policy['description_hash']) {
                continue;
            }
            $hash = hash('sha256', CanonicalPipelineJson::encode($fact->value));
            foreach ($fact->evidenceIds as $id) {
                $proof = $evidence[$id] ?? null;
                if ($proof !== null && [$proof->organizationId, $proof->projectId, $proof->sessionId] === $scope
                    && $proof->sourceVersion === $fact->sourceVersion && $proof->sourceType === 'user_input'
                    && $proof->producerName === 'user_input_normalizer' && $proof->nativeReference === 'input_payload.description'
                    && $proof->sourceReference === 'input:'.$scope[2] && $proof->choiceHash === $hash) {
                    $matches[$fact->id] = $fact;
                }
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private static function dependencies(ProjectModelSnapshot $snapshot, array $factIds, array $scope): ?array
    {
        if (count($factIds) > self::MAX_COMPOSER_DEPENDENCIES) {
            return null;
        }
        $facts = array_column($snapshot->facts, null, 'id');
        $entities = array_column($snapshot->entities, null, 'id');
        $proofs = array_column($snapshot->evidence, null, 'id');
        $result = [];
        foreach ($factIds as $id) {
            $fact = $facts[$id] ?? null;
            $entity = $fact === null ? null : ($entities[$fact->entityId] ?? null);
            if ($fact === null || $entity === null || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== $scope
                || [$entity->organizationId, $entity->projectId, $entity->sessionId] !== $scope || $fact->evidenceIds === []) {
                return null;
            }
            $evidence = [];
            foreach ($fact->evidenceIds as $proofId) {
                $proof = $proofs[$proofId] ?? null;
                if ($proof === null || [$proof->organizationId, $proof->projectId, $proof->sessionId] !== $scope
                    || $proof->sourceVersion !== $fact->sourceVersion) {
                    return null;
                }
                $evidence[$proofId] = ['id' => $proof->id, 'source_version' => $proof->sourceVersion, 'source_type' => $proof->sourceType,
                    'artifact' => $proof->sourceArtifactId, 'reference' => $proof->sourceReference, 'native_reference' => $proof->nativeReference,
                    'producer' => $proof->producerName, 'choice_hash' => $proof->choiceHash, 'fingerprint' => $proof->fingerprint,
                    'page' => $proof->page, 'region' => $proof->region, 'document_role' => $proof->documentRole];
            }
            ksort($evidence, SORT_STRING);
            $result[] = ['fact_id' => $fact->id, 'version' => $fact->version, 'source_version' => $fact->sourceVersion,
                'entity_id' => $fact->entityId, 'value_hash' => DocumentRequirementAcceptance::valueHash($fact),
                'origin' => $fact->origin, 'status' => $fact->status, 'evidence' => array_values($evidence),
                'entity_hash' => hash('sha256', CanonicalPipelineJson::encode(['type' => $entity->type, 'source_version' => $entity->sourceVersion,
                    'attributes' => $entity->attributes]))];
        }

        return $result;
    }

    /** @param list<mixed> $questions */
    public function resolve(
        array $questions,
        string $understandingSourceVersion,
        ProjectModelSnapshot $snapshot,
        string $snapshotToken,
        string $questionKey,
    ): ?CurrentEstimateClarification {
        foreach ($this->resolveAll($questions, $understandingSourceVersion, $snapshot, $snapshotToken) as $candidate) {
            if ($candidate->question->code === $questionKey) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param list<mixed> $questions @return list<CurrentEstimateClarification> */
    public function resolveAll(
        array $questions,
        string $understandingSourceVersion,
        ProjectModelSnapshot $snapshot,
        string $snapshotToken,
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1) {
            throw new InvalidArgumentException('estimate_clarification_snapshot_token_invalid');
        }
        $result = [];
        foreach ($this->projector->project($questions, $understandingSourceVersion) as $question) {
            $resolved = $this->resolveQuestion($question, $snapshot, $snapshotToken);
            if ($resolved !== null) {
                $result[] = $resolved;
            }
        }

        return $result;
    }

    private function resolveQuestion(
        EstimateClarificationQuestion $question,
        ProjectModelSnapshot $snapshot,
        string $snapshotToken,
    ): ?CurrentEstimateClarification {
        $factIds = is_array($question->sourceLocator['fact_ids'] ?? null)
            ? $question->sourceLocator['fact_ids']
            : [];
        $evidenceIds = is_array($question->sourceLocator['evidence_ids'] ?? null)
            ? $question->sourceLocator['evidence_ids']
            : [];
        $facts = array_values(array_filter(
            $snapshot->facts,
            static fn (Fact $fact): bool => in_array($fact->id, $factIds, true)
                || array_intersect($fact->evidenceIds, $evidenceIds) !== [],
        ));
        if ($facts === []) {
            return null;
        }
        usort($facts, static function (Fact $left, Fact $right): int {
            $rank = static fn (Fact $fact): int => match ($fact->status) {
                'unresolved' => 0,
                'candidate' => 1,
                default => 2,
            };

            return [$rank($left), $left->id] <=> [$rank($right), $right->id];
        });
        $target = $facts[0];
        $fingerprint = hash('sha256', CanonicalPipelineJson::encode([
            'question' => $question->toArray(),
            'snapshot_token' => $snapshotToken,
            'target' => [
                'id' => $target->id,
                'source_version' => $target->sourceVersion,
                'value' => $target->value,
                'unit' => $target->unit,
                'status' => $target->status,
                'version' => $target->version,
                'evidence_ids' => $target->evidenceIds,
            ],
        ]));

        return new CurrentEstimateClarification(
            $question,
            $target->sourceVersion,
            $snapshotToken,
            $fingerprint,
            $target->id,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectUnderstandingQuestionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerClarificationTest extends TestCase
{
    public function test_local_work_context_is_filtered_before_aggregate_cap_and_rejects_invalid_targets(): void
    {
        [$draft, $input, $snapshot] = $this->fixture('universal');
        $work = static function (string $key): array {
            $intent = ['kind' => 'supplementary', 'work_key' => $key, 'name' => 'Монтаж неизвестной установки',
                'entity_id' => 'entity:equipment', 'work_section' => 'equipment', 'unit' => 'count',
                'source_fact_ids' => ['fact:specification'], 'technology_clarification' => [
                    'question' => 'Как выполняется крепление этой установки?', 'reason' => 'Способ крепления не указан в исходных данных.']];

            return ['key' => 'position:'.$key, 'work_key' => $key, 'name' => $intent['name'], 'composition_intent' => $intent,
                'metadata' => ['scope_entity_id' => 'entity:equipment']];
        };
        $locals = [['key' => 'local:target', 'sections' => [['key' => 'section:target', 'work_items' => [$work('custom_target')]]]],
            ['key' => 'local:foreign', 'sections' => [['key' => 'section:foreign',
                'work_items' => array_map(static fn (int $i): array => $work('custom_foreign_'.$i), range(1, 201))]]]];
        $locals = ResolveCurrentEstimateClarification::bindWorkTechnologyContexts($locals, $snapshot, [10, 20, 30],
            ResolveCurrentEstimateClarification::composerPolicy($input), []);
        $draft = (new BuildMostEstimateDraft)->seal([...$draft, 'source_info_only' => false, 'local_estimates' => $locals]);
        try {
            $entries = $this->resolver()->workTechnologyContexts($draft, $input, $snapshot, [], [10, 20, 30], [], 'local:target');
        } catch (\InvalidArgumentException $error) {
            self::fail('Local target must be filtered before aggregate cap: '.$error->getMessage());
        }
        self::assertCount(1, $entries);
        self::assertSame('custom_target', $entries[0]['context']['binding']['work_key']);
        foreach (['local:missing', '', str_repeat('x', 256)] as $key) {
            self::assertSame([], $this->resolver()->workTechnologyContexts($draft, $input, $snapshot, [], [10, 20, 30], [], $key));
        }
        $ambiguous = (new BuildMostEstimateDraft)->seal([...$draft, 'local_estimates' => [$locals[0], $locals[0]]]);
        self::assertSame([], $this->resolver()->workTechnologyContexts($ambiguous, $input, $snapshot, [], [10, 20, 30], [], 'local:target'));
        self::assertSame([], $this->resolver()->workTechnologyContexts([...$draft, 'title' => 'Подменённый полный артефакт'],
            $input, $snapshot, [], [10, 20, 30], [], 'local:target'));
        $this->expectExceptionMessage('estimate_composer_entity_context_too_large');
        $this->resolver()->workTechnologyContexts($draft, $input, $snapshot, [], [10, 20, 30], []);
    }

    public function test_work_context_rechecks_dependencies_of_an_existing_aggregate_answer_source(): void
    {
        [$input, $snapshot, $answer, $decision] = $this->answered('universal', 'entity:equipment');
        $intent = ['kind' => 'supplementary', 'candidate_id' => 'supplementary:answer', 'work_key' => 'custom_installation',
            'name' => 'Монтаж оборудования', 'entity_id' => 'entity:equipment', 'work_section' => 'equipment', 'unit' => 'count',
            'source_fact_ids' => [$answer->id], 'technology_clarification' => ['question' => 'Какой способ монтажа требуется?', 'reason' => 'Способ не указан в ответе.']];
        $locals = [['sections' => [['work_items' => [['work_key' => $intent['work_key'], 'name' => $intent['name'],
            'composition_intent' => $intent, 'metadata' => ['scope_entity_id' => 'entity:equipment']]]]]]];
        $locals = ResolveCurrentEstimateClarification::bindWorkTechnologyContexts($locals, $snapshot, [10, 20, 30],
            ResolveCurrentEstimateClarification::composerPolicy($input), []);
        $draft = (new BuildMostEstimateDraft)->seal(['scope_identity' => ['organization_id' => 10, 'project_id' => 20, 'session_id' => 30],
            'local_estimates' => $locals]);
        self::assertCount(1, $this->resolver()->workTechnologyContexts($draft, $input, $snapshot, [$decision], [10, 20, 30], []));
        $source = $snapshot->facts[1];
        $replacement = new Fact($source->id, 10, 20, 30, $source->sourceVersion, $source->entityId, $source->type,
            'Изменённая исходная спецификация', null, 0.0, 'document', 'confirmed', $source->evidenceIds, 2);
        $changed = new ProjectModelSnapshot($snapshot->entities, [$snapshot->facts[0], $replacement, $answer], $snapshot->evidence, []);
        self::assertSame([], $this->resolver()->workTechnologyContexts($draft, $input, $changed, [$decision], [10, 20, 30], []));
    }

    #[DataProvider('modes')]
    public function test_work_question_context_survives_candidate_changes_and_omission_but_rejects_replacement_drift_and_ambiguity(string $mode): void
    {
        [$draft, $input, $snapshot] = $this->fixture($mode);
        $intent = ['kind' => 'supplementary', 'candidate_id' => 'supplementary:first', 'work_key' => 'custom_installation',
            'name' => 'Монтаж неизвестной установки', 'entity_id' => 'entity:equipment', 'work_section' => 'equipment',
            'unit' => 'count', 'source_fact_ids' => ['fact:specification'],
            'technology_clarification' => ['question' => 'Как выполняется крепление этой установки?', 'reason' => 'Способ крепления не указан в исходных данных.']];
        $locals = [['sections' => [['work_items' => [['key' => 'position:first', 'work_key' => $intent['work_key'], 'name' => $intent['name'],
            'composition_intent' => $intent, 'metadata' => ['scope_entity_id' => 'entity:equipment']]]]]]];
        $policy = ResolveCurrentEstimateClarification::composerPolicy($input);
        $locals = ResolveCurrentEstimateClarification::bindWorkTechnologyContexts($locals, $snapshot, [10, 20, 30], $policy, []);
        $draft = (new BuildMostEstimateDraft)->seal([...$draft, 'source_info_only' => false, 'local_estimates' => $locals]);
        $entries = $this->resolver()->workTechnologyContexts($draft, $input, $snapshot, [], [10, 20, 30], []);
        self::assertCount(1, $entries);
        $questions = $this->resolver()->workTechnologyQuestions($entries, str_repeat('a', 64), 7);
        self::assertSame('composer_work_technology', $questions[0]->question->sourceLocator['kind']);
        self::assertNull($questions[0]->workAnswer);
        self::assertSame('unanswered', $entries[0]['status']);
        self::assertSame([], $this->resolver()->workTechnologyContexts($draft,
            [...$input, 'scope_selection' => ['work_scope' => ['selected_items' => [['key' => 'position:first']]]]],
            $snapshot, [], [10, 20, 30], []));
        $next = $locals;
        unset($next[0]['sections'][0]['work_items'][0]['metadata']['work_technology_context'],
            $next[0]['sections'][0]['work_items'][0]['composition_intent']['technology_clarification']);
        $next[0]['sections'][0]['work_items'][0]['composition_intent']['candidate_id'] = 'supplementary:second';
        $next[0]['sections'][0]['work_items'][0]['key'] = 'position:second';
        $carried = ResolveCurrentEstimateClarification::bindWorkTechnologyContexts($next, $snapshot, [10, 20, 30], $policy, $entries);
        $nextDraft = (new BuildMostEstimateDraft)->seal([...$draft, 'local_estimates' => $carried]);
        $nextEntries = $this->resolver()->workTechnologyContexts($nextDraft, $input, $snapshot, [], [10, 20, 30], []);
        self::assertSame($entries, $nextEntries);
        self::assertSame($questions[0]->question->code, $this->resolver()->workTechnologyQuestions($nextEntries, str_repeat('b', 64), 8)[0]->question->code);
        self::assertNotSame($questions[0]->answerFingerprint, $this->resolver()->workTechnologyQuestions($nextEntries, str_repeat('b', 64), 8)[0]->answerFingerprint);
        $source = $snapshot->facts[1];
        $changed = new Fact($source->id, 10, 20, 30, $source->sourceVersion, $source->entityId, $source->type,
            'Изменённый источник', null, 0.0, 'document', 'confirmed', $source->evidenceIds, 2);
        self::assertSame([], $this->resolver()->workTechnologyContexts($draft, $input,
            new ProjectModelSnapshot($snapshot->entities, [$snapshot->facts[0], $changed], $snapshot->evidence, []), [], [10, 20, 30], []));
        self::assertSame([], $this->resolver()->workTechnologyContexts($draft, $input, $snapshot, [], [11, 20, 30], []));
        self::assertSame([], $this->resolver()->workTechnologyContexts([...$draft, 'title' => 'Подменено'], $input, $snapshot, [], [10, 20, 30], []));
        $duplicate = (new BuildMostEstimateDraft)->seal([...$draft, 'local_estimates' => [...$locals, ...$locals]]);
        self::assertSame([], $this->resolver()->workTechnologyContexts($duplicate, $input, $snapshot, [], [10, 20, 30], []));
        $next[0]['sections'][0]['work_items'][0]['name'] = 'Другая работа';
        $next[0]['sections'][0]['work_items'][0]['composition_intent']['name'] = 'Другая работа';
        $replaced = ResolveCurrentEstimateClarification::bindWorkTechnologyContexts($next, $snapshot, [10, 20, 30], $policy, $entries);
        self::assertArrayNotHasKey('work_technology_context', $replaced[0]['sections'][0]['work_items'][0]['metadata']);
    }

    #[DataProvider('modes')]
    public function test_current_sealed_requests_produce_one_aggregate_other_question_without_a_target_fact(string $mode): void
    {
        [$draft, $input, $snapshot] = $this->fixture($mode);
        $question = $this->resolver()->resolveComposer($draft, $input, $snapshot, str_repeat('a', 64), [10, 20, 30]);

        self::assertNotNull($question);
        self::assertNull($question->targetFactId);
        self::assertStringStartsWith('composer_source_', $question->question->code);
        self::assertSame(['other', 'leave_unresolved'], array_column($question->question->choices, 'kind'));
        self::assertSame(2, $question->question->sourceLocator['request_count']);
        self::assertSame([0, 1], $question->question->sourceLocator['request_indices']);
        self::assertSame(['fact:description', 'fact:specification'], $question->question->sourceLocator['source_fact_ids']);
        self::assertSame('composer_request_info', $question->question->sourceLocator['kind']);
        self::assertStringNotContainsString('estimate_composer_', $question->question->subject.$question->question->reason);
        self::assertArrayNotHasKey('dependencies', $question->question->sourceLocator);
        self::assertCount(2, $question->composerContext['dependencies']);
        self::assertSame($question->sourceVersion, 'sha256:'.$draft['artifact_hash']);
    }

    public static function modes(): array
    {
        return [['legacy'], ['universal']];
    }

    public function test_target_options_are_outside_the_hashed_question_and_only_include_frozen_document_context(): void
    {
        [$draft, $input, $snapshot] = $this->fixture('universal');
        $current = $this->resolver()->resolveComposer($draft, $input, $snapshot, str_repeat('a', 64), [10, 20, 30]);
        self::assertSame([['entity_id' => 'entity:equipment', 'label' => 'Установка из спецификации (Оборудование!C2)']], $current->targetOptions);
        self::assertArrayNotHasKey('target_options', $current->question->toArray());
        self::assertArrayNotHasKey('target_options', $current->composerContext);
        self::assertSame(hash('sha256', CanonicalPipelineJson::encode(['question' => $current->question->toArray(),
            'snapshot_token' => str_repeat('a', 64), 'context' => $current->composerContext])), $current->answerFingerprint);
        $document = $snapshot->facts[1];
        $late = new Fact('fact:late', 10, 20, 30, $document->sourceVersion, 'entity:late', $document->type,
            'Поздняя спецификация', null, 0.0, 'document', 'confirmed', $document->evidenceIds);
        $withLate = new ProjectModelSnapshot([...$snapshot->entities, new Entity('entity:late', 10, 20, 30, $document->sourceVersion,
            'equipment', 'late', ['semantic_type' => 'equipment'])], [...$snapshot->facts, $late], $snapshot->evidence, []);
        self::assertSame($current->targetOptions, $this->resolver()->resolveComposer($draft, $input, $withLate, str_repeat('a', 64), [10, 20, 30])->targetOptions);
        $numeric = new Fact($document->id, 10, 20, 30, $document->sourceVersion, $document->entityId,
            'equipment_count', 3, 'count', 0.0, 'document', 'confirmed', $document->evidenceIds);
        self::assertSame([], $this->resolver()->resolveComposer($draft, $input,
            new ProjectModelSnapshot($snapshot->entities, [$snapshot->facts[0], $numeric], $snapshot->evidence, []),
            str_repeat('a', 64), [10, 20, 30])->targetOptions);
    }

    public function test_old_tampered_foreign_and_non_actionable_artifacts_do_not_issue_a_question(): void
    {
        [$draft, $input, $snapshot] = $this->fixture('universal');
        self::assertNull($this->resolver()->resolveComposer($draft, $input, $snapshot, str_repeat('b', 64), [10, 20, 30]));
        self::assertNull($this->resolver()->resolveComposer([...$draft, 'title' => 'Подменено'], $input, $snapshot, str_repeat('a', 64), [10, 20, 30]));
        self::assertNull($this->resolver()->resolveComposer($draft, $input, $snapshot, str_repeat('a', 64), [11, 20, 30]));
        $ignored = $draft;
        $ignored['estimate_composition']['request_info'] = [['index' => 0, 'reason' => 'estimate_composer_work_section_not_selected',
            'work_name' => 'Монтаж оборудования', 'next_action' => 'confirm_work_source', 'source_fact_ids' => ['fact:description']]];
        self::assertNull($this->resolver()->resolveComposer((new BuildMostEstimateDraft)->seal($ignored), $input, $snapshot, str_repeat('a', 64), [10, 20, 30]));
        $changed = [...$input, 'selected_sections' => ['finishing']];
        self::assertNull($this->resolver()->resolveComposer($draft, $changed, $snapshot, str_repeat('a', 64), [10, 20, 30]));
    }

    public function test_a_document_or_forged_description_cannot_replace_the_genuine_user_request(): void
    {
        [$draft, $input, $snapshot] = $this->fixture('legacy');
        $original = $snapshot->facts[0];
        foreach (['document', 'user_assumption'] as $origin) {
            $forged = new Fact($original->id, 10, 20, 30, $original->sourceVersion, $original->entityId, $original->type,
                $original->value, null, 0.0, $origin, 'confirmed', $original->evidenceIds);
            self::assertNull($this->resolver()->resolveComposer($draft, $input,
                new ProjectModelSnapshot($snapshot->entities, [$forged, $snapshot->facts[1]], $snapshot->evidence, []), str_repeat('a', 64), [10, 20, 30]));
        }
        $withoutProof = new ProjectModelSnapshot($snapshot->entities, $snapshot->facts, [$snapshot->evidence[1]], []);
        self::assertNull($this->resolver()->resolveComposer($draft, $input, $withoutProof, str_repeat('a', 64), [10, 20, 30]));
    }

    #[DataProvider('modes')]
    public function test_answer_reuse_rechecks_exact_dependencies_without_requiring_the_pre_answer_snapshot(string $mode): void
    {
        [$input, $snapshot, $answer, $decision] = $this->answered($mode);
        $context = ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, [$decision], $input);
        self::assertNotNull($context);
        self::assertSame('entity:request', $context['entity_id']);
        $independent = new Fact('fact:independent', 10, 20, 30, 'sha256:'.str_repeat('f', 64), 'entity:request',
            'evaluation_answer', ['note' => 'Независимое уточнение'], null, 0.0, 'user_assumption', 'confirmed', []);
        self::assertSame($context, ResolveCurrentEstimateClarification::composerAnswerContext($answer,
            new ProjectModelSnapshot($snapshot->entities, [...$snapshot->facts, $independent], $snapshot->evidence, []), [$decision], $input));
        $source = $snapshot->facts[1];
        $replacement = new Fact($source->id, 10, 20, 30, $source->sourceVersion, $source->entityId, $source->type,
            'Изменённая спецификация', null, 0.0, 'document', 'confirmed', $source->evidenceIds, 2);
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer,
            new ProjectModelSnapshot($snapshot->entities, [$snapshot->facts[0], $replacement, $answer], $snapshot->evidence, []), [$decision], $input));
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, [], $input));
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, [$decision], [...$input, 'description' => 'Другой запрос']));
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer,
            new ProjectModelSnapshot($snapshot->entities, [$snapshot->facts[0], $answer], $snapshot->evidence, []), [$decision], $input));
        $proofs = $snapshot->evidence;
        $proofs[2] = new Evidence('evidence:answer', 10, 20, 30, $answer->sourceVersion, 'input:30', 'user_input', null, null,
            'input_payload.evaluation_choices', 'user_input_normalizer', 'input:30', $proofs[2]->choiceHash);
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer,
            new ProjectModelSnapshot($snapshot->entities, $snapshot->facts, $proofs, []), [$decision], $input));
    }

    #[DataProvider('modes')]
    public function test_selected_target_is_rechecked_against_current_frozen_document_dependencies(string $mode): void
    {
        [$input, $snapshot, $answer, $decision] = $this->answered($mode, 'entity:equipment');
        $context = ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, [$decision], $input);
        self::assertSame('entity:equipment', $context['target_entity_id']);
        self::assertSame('entity:request', $context['entity_id']);
        foreach (['', 'entity:request', 'entity:foreign', null] as $invalid) {
            [$input, $snapshot, $answer, $decision] = $this->answered($mode, $invalid, true);
            self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, [$decision], $input));
        }
    }

    private function answered(string $mode, ?string $target = null, bool $forceTarget = false): array
    {
        [$draft, $input, $snapshot] = $this->fixture($mode);
        $current = $this->resolver()->resolveComposer($draft, $input, $snapshot, str_repeat('a', 64), [10, 20, 30]);
        $value = ['kind' => 'composer_answer', 'numeric_parameters_confirmed' => false, 'response' => 'other',
            'other' => 'Требуется только монтаж стендов по отдельной спецификации.', 'question_key' => $current->question->code,
            'question_fingerprint' => $current->answerFingerprint, 'source_locator' => $current->question->sourceLocator,
            'composer_context' => $current->composerContext, 'decision_id' => 'decision:answer', 'reason' => $current->question->subject,
            ...($target !== null || $forceTarget ? ['target_entity_id' => $target] : [])];
        $answer = new Fact('fact:answer', 10, 20, 30, $current->sourceVersion, 'entity:answer', 'evaluation_answer', $value, null,
            0.0, 'user_assumption', 'confirmed', ['evidence:answer']);
        $proof = new Evidence('evidence:answer', 10, 20, 30, $answer->sourceVersion, 'input:30', 'user_input', null, null,
            'input_payload.composer_answer', 'user_input_normalizer', 'input:30', hash('sha256', CanonicalPipelineJson::encode($value)));
        $decision = new Decision('decision:answer', 10, 20, 30, $answer->sourceVersion, 'fact', $answer->id, $answer->id,
            'user', '40', $current->question->subject, 1, $answer->evidenceIds);
        $snapshot = new ProjectModelSnapshot([...$snapshot->entities, new Entity('entity:answer', 10, 20, 30, $answer->sourceVersion,
            'quantity', 'answer', ['semantic_type' => 'quantity'])], [...$snapshot->facts, $answer], [...$snapshot->evidence, $proof], []);

        return [$input, $snapshot, $answer, $decision];
    }

    public function test_answer_context_cannot_launder_an_invalidated_source_through_a_previous_answer(): void
    {
        [$input, $snapshot, $first, $firstDecision] = $this->answered('legacy');
        [$draft] = $this->fixture('legacy');
        $draft['estimate_composition']['request_info'][0]['source_fact_ids'] = [$first->id];
        $current = $this->resolver()->resolveComposer((new BuildMostEstimateDraft)->seal($draft), $input, $snapshot, str_repeat('a', 64), [10, 20, 30]);
        self::assertNotNull($current);
        $value = [...$first->value, 'question_key' => $current->question->code, 'question_fingerprint' => $current->answerFingerprint,
            'source_locator' => $current->question->sourceLocator, 'composer_context' => $current->composerContext, 'decision_id' => 'decision:second'];
        $second = new Fact('fact:second', 10, 20, 30, $current->sourceVersion, 'entity:second', 'evaluation_answer', $value, null,
            0.0, 'user_assumption', 'confirmed', ['evidence:second']);
        $proof = new Evidence('evidence:second', 10, 20, 30, $second->sourceVersion, 'input:30', 'user_input', null, null,
            'input_payload.composer_answer', 'user_input_normalizer', 'input:30', hash('sha256', CanonicalPipelineJson::encode($value)));
        $decision = new Decision('decision:second', 10, 20, 30, $second->sourceVersion, 'fact', $second->id, $second->id,
            'user', '40', $current->question->subject, 1, $second->evidenceIds);
        $entities = [...$snapshot->entities, new Entity('entity:second', 10, 20, 30, $second->sourceVersion,
            'quantity', 'second', ['semantic_type' => 'quantity'])];
        $facts = [...$snapshot->facts, $second];
        $evidence = [...$snapshot->evidence, $proof];
        self::assertNotNull(ResolveCurrentEstimateClarification::composerAnswerContext($second,
            new ProjectModelSnapshot($entities, $facts, $evidence, []), [$firstDecision, $decision], $input));
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($second,
            new ProjectModelSnapshot($entities, [$facts[0], $facts[1], $second], $evidence, []), [$firstDecision, $decision], $input));
        $visiting = [$first->id => null];
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($second,
            new ProjectModelSnapshot($entities, $facts, $evidence, []), [$firstDecision, $decision], $input, $visiting));
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($second,
            new ProjectModelSnapshot($entities, $facts, $evidence, []), [$decision], $input));
        $source = $facts[1];
        $facts[1] = new Fact($source->id, 10, 20, 30, $source->sourceVersion, $source->entityId, $source->type,
            'Изменённая спецификация', null, 0.0, 'document', 'confirmed', $source->evidenceIds, 2);
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($first,
            new ProjectModelSnapshot($entities, $facts, $evidence, []), [$firstDecision, $decision], $input));
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($second,
            new ProjectModelSnapshot($entities, $facts, $evidence, []), [$firstDecision, $decision], $input));
    }

    public function test_answer_history_rechecks_at_most_two_hundred_distinct_answers_without_dropping_the_capture_guard(): void
    {
        [$input, $snapshot, $answer, $decision] = $this->answered('legacy');
        $decisions = [$decision];
        [$draft] = $this->fixture('legacy');
        $within = null;
        for ($index = 2; $index <= 201; $index++) {
            $draft['estimate_composition']['request_info'] = [['index' => 0, 'reason' => 'estimate_composer_work_scope_unresolved',
                'work_name' => null, 'next_action' => 'provide_work_scope', 'source_fact_ids' => [$answer->id]]];
            $current = $this->resolver()->resolveComposer((new BuildMostEstimateDraft)->seal($draft), $input, $snapshot, str_repeat('a', 64), [10, 20, 30]);
            self::assertNotNull($current);
            $value = [...$answer->value, 'question_key' => $current->question->code, 'question_fingerprint' => $current->answerFingerprint,
                'source_locator' => $current->question->sourceLocator, 'composer_context' => $current->composerContext, 'decision_id' => 'decision:chain:'.$index];
            $answer = new Fact('fact:chain:'.$index, 10, 20, 30, $current->sourceVersion, 'entity:chain:'.$index,
                'evaluation_answer', $value, null, 0.0, 'user_assumption', 'confirmed', ['evidence:chain:'.$index]);
            $proof = new Evidence('evidence:chain:'.$index, 10, 20, 30, $answer->sourceVersion, 'input:30', 'user_input', null, null,
                'input_payload.composer_answer', 'user_input_normalizer', 'input:30', hash('sha256', CanonicalPipelineJson::encode($value)));
            $decisions[] = new Decision('decision:chain:'.$index, 10, 20, 30, $answer->sourceVersion, 'fact', $answer->id, $answer->id,
                'user', '40', $current->question->subject, 1, $answer->evidenceIds);
            $snapshot = new ProjectModelSnapshot([...$snapshot->entities, new Entity($answer->entityId, 10, 20, 30, $answer->sourceVersion,
                'quantity', 'chain:'.$index, ['semantic_type' => 'quantity'])], [...$snapshot->facts, $answer], [...$snapshot->evidence, $proof], []);
            if ($index === 200) {
                $within = $answer;
            }
        }
        self::assertNotNull(ResolveCurrentEstimateClarification::composerAnswerContext($within, $snapshot, $decisions, $input));
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, $decisions, $input));
    }

    private function resolver(): ResolveCurrentEstimateClarification
    {
        $translate = static fn (string $key): string => match ($key) {
            'estimate_generation.composer_clarification.subject' => 'Какие работы требуется включить в смету?',
            'estimate_generation.composer_clarification.reason' => 'По текущим данным состав работ определить не удалось.',
            'estimate_generation.composer_clarification.impact' => 'Ответ уточнит предварительный состав; объёмы и цены требуют отдельного основания.',
            'estimate_generation.composer_clarification.recommendation' => 'Опишите состав и назначение работ одним ответом до 500 символов. Документы можно приложить отдельно.',
            'estimate_generation.composer_clarification.summary' => 'Всего запросов исходных данных: :count.',
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            'estimate_generation.work_technology.impact' => 'Качественный ответ не заменяет независимые проверки.',
            'estimate_generation.work_technology.recommendation' => 'Укажите способ выполнения и документы.',
            default => $key,
        };

        return new ResolveCurrentEstimateClarification(new ProjectUnderstandingQuestionProjector($translate), $translate);
    }

    private function fixture(string $mode): array
    {
        $version = 'sha256:'.str_repeat('d', 64);
        $value = ['kind' => 'initial_description', 'description' => 'Монтаж неизвестной установки и покрытия', 'numeric_parameters_confirmed' => false];
        $description = new Fact('fact:description', 10, 20, 30, $version, 'entity:request', 'evaluation_answer', $value, null,
            0.0, 'user_input', 'confirmed', ['evidence:description']);
        $proof = new Evidence('evidence:description', 10, 20, 30, $version, 'input:30', 'user_input', null, null,
            'input_payload.description', 'user_input_normalizer', 'input:30', hash('sha256', CanonicalPipelineJson::encode($value)));
        $specification = new Fact('fact:specification', 10, 20, 30, $version, 'entity:equipment', 'equipment_specification',
            'Установка из спецификации', null, 0.0, 'document', 'confirmed', ['evidence:specification']);
        $document = new Evidence('evidence:specification', 10, 20, 30, $version, 'document:40', 'document', null, null,
            'xlsx:sheet:Оборудование!C2', 'drawing_analyzer', 'document:40', fingerprint: str_repeat('e', 64));
        $entities = [new Entity('entity:request', 10, 20, 30, $version, 'quantity', 'request', ['semantic_type' => 'quantity']),
            new Entity('entity:equipment', 10, 20, 30, $version, 'equipment', 'equipment', ['semantic_type' => 'equipment'])];
        $input = ['evaluation_mode' => $mode, 'profile_id' => 'construction',
            'selected_sections' => $mode === 'universal' ? ['equipment'] : [], 'description' => $value['description']];
        $draft = (new BuildMostEstimateDraft)->seal([
            'source_info_only' => true, 'is_complete' => false, 'evaluation_result' => null, 'local_estimates' => [], 'stage6_candidate_rows' => [],
            'generation_contract' => $mode === 'universal' ? UniversalDraftProjector::CONTRACT : BuildMostEstimateDraft::CONTRACT,
            'scope_identity' => ['organization_id' => 10, 'project_id' => 20, 'session_id' => 30],
            'evaluation_policy' => ['mode' => $mode, 'profile_id' => 'construction', 'selected_sections' => $input['selected_sections']],
            'estimate_composition' => ['schema_version' => 1, 'snapshot_token' => str_repeat('a', 64), 'input_fingerprint' => str_repeat('b', 64),
                'request_info' => [
                    ['index' => 1, 'reason' => 'estimate_composer_work_definition_unverified', 'work_name' => 'Монтаж неизвестной установки',
                        'next_action' => 'confirm_work_source', 'source_fact_ids' => ['fact:specification', 'fact:description']],
                    ['index' => 0, 'reason' => 'estimate_composer_work_scope_unresolved', 'work_name' => null,
                        'next_action' => 'provide_work_scope', 'source_fact_ids' => []],
                ]],
        ]);

        return [$draft, $input, new ProjectModelSnapshot($entities, [$description, $specification], [$proof, $document], [])];
    }
}

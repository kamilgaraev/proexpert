<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerAllowance;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerBatchPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use PHPUnit\Framework\TestCase;

final class EstimateComposerBatchPlannerTest extends TestCase
{
    public function test_optional_cells_fit_existing_parts_once_or_are_omitted_without_cutting_mandatory_facts(): void
    {
        $facts = array_map(static fn (int $id): array => ['id' => 'fact:'.$id, 'entity_id' => 'entity:'.$id,
            'value' => str_repeat('context ', 180), 'status' => 'confirmed'], range(1, 3));
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], [], [], RunEstimateComposer::PROMPT_CONTRACT);
        $planner = new EstimateComposerBatchPlanner(3000);
        $baseline = $planner->plan($input);
        self::assertGreaterThan(1, count($baseline));
        $row = ['row_id' => 'row:'.str_repeat('a', 64), 'document_id' => 1, 'unit_id' => 2,
            'source_version' => 'sha256:'.str_repeat('a', 64), 'artifact_sha256' => 'sha256:'.str_repeat('b', 64),
            'sheet' => 'Лист', 'row' => 2, 'policy_hash' => str_repeat('c', 64), 'cells' => [
                ['cell_id' => 'cell:'.str_repeat('d', 64), 'address' => 'A2', 'raw_value' => 'Изделие'],
                ['cell_id' => 'cell:'.str_repeat('e', 64), 'address' => 'B2', 'raw_value' => 'Описание изделия'],
            ]];
        $parts = $planner->plan($input->withNativeCellContext([$row]));
        self::assertCount(count($baseline), $parts);
        self::assertSame(1, array_sum(array_map(static fn ($part): int => count($part->nativeCellContext), $parts)));
        self::assertSame(array_column($facts, 'id'), array_values(array_unique(array_merge(...array_map(static fn ($part): array => array_column($part->facts, 'id'), $parts)))));
        foreach ($parts as $part) {
            self::assertLessThanOrEqual(3000, strlen(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['project' => $part->canonicalPayload()])));
        }
        $row['cells'] = array_map(static fn (int $id): array => ['cell_id' => 'cell:'.hash('sha256', (string) $id),
            'address' => chr(65 + $id).'2', 'raw_value' => str_repeat('x', 500)], range(0, 15));
        $omitted = $planner->plan($input->withNativeCellContext([$row]));
        self::assertCount(count($baseline), $omitted);
        self::assertSame(array_map(static fn ($part) => $part->canonicalPayload(), $baseline), array_map(static fn ($part) => $part->canonicalPayload(), $omitted));
    }

    public function test_split_equipment_context_keeps_the_current_root_answer_and_reuses_its_reference_without_more_batches(): void
    {
        [$source, $reference] = $this->sourceClaims();
        $answer = $source->facts[0];
        $document = $source->facts[9];
        $facts = [$answer, $document, ['id' => 'fact:unrelated', 'entity_id' => 'room:unrelated',
            'value' => str_repeat('context ', 350), 'status' => 'confirmed']];
        $candidates = $source->candidates;
        $candidates[0]['source_fact_ids'] = [$answer['id']];
        $candidates[1]['source_fact_ids'] = [$document['id']];
        $decision = ['id' => 'decision:answer', 'selected_fact_id' => $answer['id'], 'target_id' => $answer['id']];
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [$decision], $candidates, [],
            RunEstimateComposer::ANSWER_PROMPT_CONTRACT, allowedWorkSections: ['equipment']);
        $bytes = strlen(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['project' => $input->canonicalPayload()]));
        $planner = new EstimateComposerBatchPlanner(maxInputBytes: $bytes - 1, candidatesPerBatch: 1);
        $plain = $planner->plan($input);
        $parts = $planner->plan($input->withSourceClaimReferences([$reference]));
        self::assertCount(3, $plain);
        self::assertCount(count($plain), $parts);
        $documentParts = array_values(array_filter($parts, static fn (EstimateComposerInput $part): bool => in_array($document['id'], array_column($part->facts, 'id'), true)));
        self::assertCount(1, $documentParts);
        $part = $documentParts[0];
        self::assertContains($answer['id'], $part->claimAnswerFactIds);
        $partFacts = array_column($part->facts, null, 'id');
        self::assertSame($answer, $partFacts[$answer['id']]);
        self::assertSame('project:root', $partFacts[$answer['id']]['entity_id']);
        self::assertSame('equipment:child', $partFacts[$document['id']]['entity_id']);
        self::assertSame([$decision], $part->decisions);
        self::assertSame([$reference], $part->sourceClaimReferences);
        self::assertSame(1, array_sum(array_map(static fn (EstimateComposerInput $part): int => count($part->sourceClaimReferences), $parts)));
        $seen = [];
        foreach ($parts as $batch) {
            self::assertLessThanOrEqual($bytes - 1, strlen(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['project' => $batch->canonicalPayload()])));
            foreach ($batch->facts as $fact) {
                $seen[$fact['id']] = true;
            }
        }
        self::assertCount(count($facts), $seen);
        $claim = ['source_answer_fact_id' => $answer['id'], 'entity_id' => $document['entity_id'],
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'AISI 316'],
            'unit' => null, 'document_fact_ids' => [$document['id']]];
        self::assertTrue(EstimateComposerInput::sourceClaimCandidate($claim, $part->facts, $part->claimAnswerFactIds));
        $plainDocument = array_values(array_filter($plain, static fn (EstimateComposerInput $part): bool => in_array($document['id'], array_column($part->facts, 'id'), true)))[0];
        self::assertTrue(EstimateComposerInput::sourceClaimCandidate($claim, $plainDocument->facts, $plainDocument->claimAnswerFactIds));
        $model = new class($claim) implements EstimateComposerModel
        {
            public int $calls = 0;

            public function __construct(private array $claim) {}

            public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
            {
                $this->calls++;
                $onPhysicalAttemptReserved(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext::deterministicId($input->fingerprint()));

                return ['work_intents' => [], 'claim_candidates' => EstimateComposerInput::sourceClaimCandidate($this->claim, $input->facts, $input->claimAnswerFactIds) ? [$this->claim] : []];
            }
        };
        $runs = new BatchComposerMemoryRepository;
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna', $planner);
        $outcome = $composer->runDetailed($input->withSourceClaimReferences([$reference]));
        self::assertSame($outcome, $composer->runDetailed($input->withSourceClaimReferences([$reference])));
        self::assertSame(count($parts), $model->calls);
        self::assertSame(1, array_sum(array_map(static fn (AiRoleRunResult $result): int => count($result->payload['claim_candidates']), $runs->results)));
    }

    public function test_missing_or_revoked_answers_are_not_admitted_to_split_equipment_context(): void
    {
        [$source] = $this->sourceClaims();
        foreach (['missing', 'revoked'] as $state) {
            $facts = [$source->facts[0], $source->facts[9]];
            $facts[0]['status'] = 'unresolved';
            if ($state === 'missing') {
                array_shift($facts);
            }
            $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], $source->candidates, [],
                RunEstimateComposer::ANSWER_PROMPT_CONTRACT, allowedWorkSections: ['equipment']);
            self::assertSame([], $input->claimAnswerFactIds);
            foreach ((new EstimateComposerBatchPlanner(candidatesPerBatch: 1))->plan($input) as $part) {
                self::assertSame([], $part->claimAnswerFactIds);
                self::assertSame([], $part->sourceClaimReferences);
            }
        }
    }

    public function test_split_context_prioritizes_the_saved_reference_anchor_within_the_global_eight_answer_limit(): void
    {
        [$source, $reference] = $this->sourceClaims();
        $facts = $source->facts;
        $facts[9]['value'] = $reference['value']['data'] = str_repeat('Б', 500);
        $facts[] = ['id' => 'fact:unrelated', 'entity_id' => 'room:unrelated', 'value' => str_repeat('C', 1000), 'status' => 'confirmed'];
        $reference['source_answer_fact_id'] = 'answer:08';
        $candidates = $source->candidates;
        $candidates[0]['source_fact_ids'] = ['answer:01'];
        $candidates[1]['source_fact_ids'] = ['fact:document'];
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], $candidates, [],
            RunEstimateComposer::ANSWER_PROMPT_CONTRACT, allowedWorkSections: ['equipment']);
        $parts = (new EstimateComposerBatchPlanner(maxInputBytes: 5000, candidatesPerBatch: 1))->plan($input->withSourceClaimReferences([$reference]));
        self::assertCount(3, $parts);
        $part = array_values(array_filter($parts, static fn (EstimateComposerInput $part): bool => in_array('fact:document', array_column($part->facts, 'id'), true)))[0];
        self::assertContains('answer:08', $part->claimAnswerFactIds);
        self::assertNotContains('answer:09', array_column($part->facts, 'id'));
        self::assertLessThanOrEqual(8, count($part->claimAnswerFactIds));
        self::assertSame([$reference], $part->sourceClaimReferences);
        self::assertLessThanOrEqual(5000, strlen(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['project' => $part->canonicalPayload()])));
    }

    public function test_nonfitting_answer_anchor_neither_creates_a_part_nor_removes_mandatory_context(): void
    {
        [$source, $reference] = $this->sourceClaims();
        $facts = [$source->facts[0], $source->facts[9]];
        $facts[0]['value']['answer'] = str_repeat('A', 500);
        $facts[1]['value'] = $reference['value']['data'] = str_repeat('B', 500);
        foreach (range(1, 8) as $index) {
            $facts[] = ['id' => 'fact:other:'.$index, 'entity_id' => 'room:'.$index,
                'value' => str_repeat('C', 500), 'status' => 'confirmed'];
        }
        $candidates = $source->candidates;
        $candidates[0]['source_fact_ids'] = [$facts[0]['id']];
        $candidates[1]['source_fact_ids'] = [$facts[1]['id']];
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], $candidates, [],
            RunEstimateComposer::ANSWER_PROMPT_CONTRACT, allowedWorkSections: ['equipment']);
        $withoutClaims = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], $candidates, [],
            RunEstimateComposer::ANSWER_PROMPT_CONTRACT, allowedWorkSections: ['equipment'], claimAnswerFactIds: []);
        $planner = new EstimateComposerBatchPlanner(maxInputBytes: 1800, candidatesPerBatch: 1);
        $baseline = $planner->plan($withoutClaims);
        $parts = $planner->plan($input->withSourceClaimReferences([$reference]));
        self::assertCount(count($baseline), $parts);
        $seen = [];
        foreach ($parts as $part) {
            self::assertLessThanOrEqual(1800, strlen(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['project' => $part->canonicalPayload()])));
            self::assertSame([], $part->sourceClaimReferences);
            if (in_array('fact:document', array_column($part->facts, 'id'), true)) {
                self::assertSame([], $part->claimAnswerFactIds);
            }
            foreach ($part->facts as $fact) {
                $seen[$fact['id']] = true;
            }
        }
        self::assertCount(count($facts), $seen);
    }

    public function test_global_eight_answer_ids_bound_every_batch_and_refs_are_sent_only_once_without_more_calls(): void
    {
        [$input, $reference] = $this->sourceClaims();
        $planner = new EstimateComposerBatchPlanner(candidatesPerBatch: 1);
        $parts = $planner->plan($input->withSourceClaimReferences([$reference]));
        self::assertCount(2, $parts);
        self::assertCount(8, $input->claimAnswerFactIds);
        self::assertNotContains('answer:09', $input->claimAnswerFactIds);
        self::assertSame([1, 0], array_map(static fn (EstimateComposerInput $part): int => count($part->sourceClaimReferences), $parts));
        foreach ($parts as $part) {
            self::assertSame($input->claimAnswerFactIds, $part->canonicalPayload()['claim_answer_fact_ids']);
            self::assertContains('answer:09', array_column($part->facts, 'id'));
        }
        $model = new class implements EstimateComposerModel
        {
            public int $calls = 0;

            public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
            {
                $this->calls++;
                $onPhysicalAttemptReserved(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext::deterministicId($input->fingerprint()));

                return ['work_intents' => [], 'claim_candidates' => array_map(static fn (string $answer): array => [
                    'source_answer_fact_id' => $answer, 'entity_id' => 'equipment:child', 'fact_type' => 'equipment_specification',
                    'value' => ['type' => 'string', 'data' => 'AISI 316'], 'unit' => null, 'document_fact_ids' => ['fact:document'],
                ], $input->claimAnswerFactIds)];
            }
        };
        $runs = new BatchComposerMemoryRepository;
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna', $planner);
        $outcome = $composer->runDetailed($input->withSourceClaimReferences([$reference]));
        self::assertSame($outcome, $composer->runDetailed($input->withSourceClaimReferences([$reference])));
        self::assertSame(2, $model->calls);
        self::assertCount(2, $runs->results);
        foreach ($runs->results as $receipt) {
            self::assertSame(2, $receipt->payload['schema_version']);
            self::assertCount(8, $receipt->payload['claim_candidates']);
            self::assertSame($input->claimAnswerFactIds, array_column($receipt->payload['claim_candidates'], 'source_answer_fact_id'));
        }
    }

    public function test_optional_refs_do_not_create_a_batch_or_expand_the_existing_wire_budget(): void
    {
        [$input, $reference] = $this->sourceClaims();
        $bytes = strlen(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['project' => $input->canonicalPayload()]));
        $planner = new EstimateComposerBatchPlanner(maxInputBytes: $bytes + 1);
        $plain = $planner->plan($input);
        $withRef = $planner->plan($input->withSourceClaimReferences([$reference]));
        self::assertCount(1, $plain);
        self::assertCount(1, $withRef);
        self::assertSame($plain[0]->canonicalPayload(), $withRef[0]->canonicalPayload());
        self::assertArrayNotHasKey('source_claim_refs', $withRef[0]->canonicalPayload());
    }

    private function sourceClaims(): array
    {
        $facts = [];
        foreach (range(1, 9) as $index) {
            $facts[] = ['id' => sprintf('answer:%02d', $index), 'entity_id' => 'project:root', 'type' => 'evaluation_answer',
                'value' => ['kind' => 'composer_answer', 'answer' => 'Корпус AISI 316', 'numeric_parameters_confirmed' => false],
                'origin' => 'user_assumption', 'status' => 'confirmed', 'unit' => null, 'context_kind' => 'composer_answer',
                'context_entity_id' => 'project:root', 'answer_work_sections' => ['equipment']];
        }
        $facts[] = ['id' => 'fact:document', 'entity_id' => 'equipment:child', 'entity_type' => 'equipment',
            'type' => 'equipment_specification', 'value' => 'AISI 316', 'origin' => 'document', 'status' => 'confirmed', 'unit' => null];
        $candidates = [];
        foreach ([1, 2] as $index) {
            $candidates[] = ['candidate_id' => 'candidate:'.$index, 'work_key' => 'equipment:'.$index, 'name' => 'Работа '.$index,
                'unit' => null, 'quantity' => null, 'quantity_formula' => null, 'source_fact_ids' => [], 'technology_package_candidate' => null];
        }
        return [new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], $candidates, [],
            RunEstimateComposer::ANSWER_PROMPT_CONTRACT, allowedWorkSections: ['equipment']),
            ['learning_example_id' => 123, 'entity_id' => 'equipment:child', 'fact_type' => 'equipment_specification',
                'value' => ['type' => 'string', 'data' => 'AISI 316'], 'unit' => null, 'document_fact_ids' => ['fact:document'],
                'interpretation_status' => 'proposed', 'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved',
                'source_answer_fact_id' => 'answer:01']];
    }

    public function test_large_project_covers_all_work_and_unassigned_facts_and_replays_each_batch_without_another_model_call(): void
    {
        $facts = [];
        $candidates = [];
        for ($index = 0; $index < 700; $index++) {
            $facts[] = ['id' => 'fact:'.$index, 'entity_id' => 'room:'.$index, 'value' => str_repeat('word ', 200), 'status' => 'confirmed'];
            if ($index < 600) {
                $candidates[] = ['candidate_id' => 'candidate:'.$index, 'work_key' => 'work:'.$index, 'name' => 'Работа '.$index,
                    'unit' => null, 'quantity' => null, 'quantity_formula' => null, 'source_fact_ids' => ['fact:'.$index], 'technology_package_candidate' => null];
            }
        }
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], $candidates, [], RunEstimateComposer::PROMPT_CONTRACT);
        $model = new class implements EstimateComposerModel
        {
            public array $inputs = [];

            public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
            {
                $this->inputs[] = $input;
                $onPhysicalAttemptReserved(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext::deterministicId($input->fingerprint()));

                return ['work_intents' => []];
            }
        };
        $runs = new BatchComposerMemoryRepository;
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
        $first = $composer->run($input);
        self::assertSame(array_column($candidates, 'candidate_id'), array_column($first, 'candidate_id'));
        $seen = [];
        foreach ($model->inputs as $batch) {
            self::assertLessThanOrEqual(32, count($batch->candidates));
            self::assertLessThanOrEqual(524288, strlen(json_encode(['project' => $batch->canonicalPayload()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
            foreach ($batch->facts as $fact) {
                $seen[$fact['id']] = true;
            }
            foreach ($batch->candidates as $candidate) {
                self::assertContains($candidate['source_fact_ids'][0], array_column($batch->facts, 'id'));
            }
        }
        self::assertCount(700, $seen);
        $calls = count($model->inputs);
        self::assertGreaterThan(1, $calls);
        self::assertSame($first, $composer->run($input));
        self::assertCount($calls, $model->inputs);
        self::assertCount($calls, array_unique($runs->versions));
    }

    public function test_an_oversized_entity_is_rejected_before_any_part_of_the_operation_is_sent(): void
    {
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64),
            [['id' => 'fact:large', 'entity_id' => 'room:1', 'value' => str_repeat('x', 600000)]], [], [], [[
                'candidate_id' => 'candidate:1', 'work_key' => 'work:1', 'name' => 'Работа', 'unit' => null, 'quantity' => null,
                'quantity_formula' => null, 'source_fact_ids' => ['fact:large'], 'technology_package_candidate' => null,
            ]], [], RunEstimateComposer::PROMPT_CONTRACT);
        $model = $this->createMock(EstimateComposerModel::class);
        $model->expects(self::never())->method('compose');
        $runs = $this->createMock(AiRoleRunRepository::class);
        $runs->expects(self::never())->method('claim');
        $this->expectExceptionMessage('estimate_composer_entity_context_too_large');
        (new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna', new EstimateComposerBatchPlanner))->run($input);
    }

    public function test_all_logical_parts_are_approved_before_the_first_role_claim(): void
    {
        $candidates = [];
        foreach ([1, 2] as $id) {
            $candidates[] = ['candidate_id' => 'candidate:'.$id, 'work_key' => 'work:'.$id, 'name' => 'Работа '.$id,
                'unit' => null, 'quantity' => null, 'quantity_formula' => null, 'source_fact_ids' => [], 'technology_package_candidate' => null];
        }
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), [], [], [], $candidates, [], RunEstimateComposer::PROMPT_CONTRACT);
        $model = $this->createMock(EstimateComposerModel::class);
        $model->expects(self::never())->method('compose');
        $runs = $this->createMock(AiRoleRunRepository::class);
        $runs->expects(self::never())->method('claim');
        $allowance = $this->createMock(EstimateComposerAllowance::class);
        $allowance->method('partLimit')->willReturn(1);
        $this->expectExceptionMessage('estimate_composer_parts_exceed_approved_plan');
        (new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna', new EstimateComposerBatchPlanner(candidatesPerBatch: 1), $allowance))->run($input);
    }

    public function test_dependency_context_preserves_every_fact_quantity_and_parent_identity(): void
    {
        $facts = $quantities = $candidates = [];
        foreach (range(1, 80) as $id) {
            $facts[] = ['id' => 'fact:'.$id, 'entity_id' => 'room:'.$id, 'value' => str_repeat('word ', 250), 'status' => 'confirmed'];
            $quantities[] = ['id' => 'quantity:'.$id, 'value' => '10', 'unit' => 'm2', 'entity_id' => 'room:'.$id,
                'operand_fact_ids' => ['fact:'.$id]];
            if ($id <= 40) {
                $candidates[] = ['candidate_id' => 'candidate:'.$id, 'work_key' => 'work:'.$id, 'name' => 'Работа '.$id,
                    'unit' => 'm2', 'quantity' => '10', 'quantity_formula' => 'quantity:'.$id,
                    'source_fact_ids' => [], 'technology_package_candidate' => null];
            }
        }
        $root = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, $quantities, [], $candidates, [], RunEstimateComposer::PROMPT_CONTRACT);
        $parts = (new EstimateComposerBatchPlanner(maxInputBytes: 12000))->plan($root);
        $seenFacts = $seenQuantities = [];
        foreach ($parts as $part) {
            self::assertSame($root->fingerprint(), $part->contextManifest['parent_input_fingerprint']);
            foreach ($part->facts as $fact) {
                $seenFacts[$fact['id']] = true;
            }
            foreach ($part->derivedQuantities as $quantity) {
                $seenQuantities[$quantity['id']] = true;
                self::assertContains($quantity['operand_fact_ids'][0], array_column($part->facts, 'id'));
            }
            foreach ($part->candidates as $candidate) {
                self::assertContains($candidate['quantity_formula'], array_column($part->derivedQuantities, 'id'));
            }
        }
        self::assertCount(80, $seenFacts);
        self::assertCount(80, $seenQuantities);
        $facts[79]['value'] = 'Changed outside the first part';
        $changed = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, $quantities, [], $candidates, [], RunEstimateComposer::PROMPT_CONTRACT);
        self::assertNotSame($parts[0]->fingerprint(), (new EstimateComposerBatchPlanner(maxInputBytes: 12000))->plan($changed)[0]->fingerprint());
    }

    public function test_missing_quantity_operand_is_rejected_even_when_the_whole_input_is_small(): void
    {
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), [],
            [['id' => 'quantity:1', 'value' => '10', 'unit' => 'm2', 'operand_fact_ids' => ['fact:missing']]], [], [], [], RunEstimateComposer::PROMPT_CONTRACT);
        $this->expectExceptionMessage('estimate_composer_quantity_operand_missing');
        (new EstimateComposerBatchPlanner)->plan($input);
    }
}

final class BatchComposerMemoryRepository implements AiRoleRunRepository
{
    public array $versions = [];

    public array $results = [];

    private array $keys = [];

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $key = $input->identityFingerprint();
        if (isset($this->results[$key])) {
            return new AiRoleRunClaim($this->keys[$key], 'replay', result: $this->results[$key]);
        }
        $this->versions[] = $input->subjectVersion;
        $id = count($this->keys) + 1;
        $this->keys[$key] = $id;

        return new AiRoleRunClaim($id, 'owned', $ownerUuid);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId, ?string $creditOperationId = null, ?string $creditRole = null): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->results[array_search($runId, $this->keys, true)] = $result;
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void {}

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        return null;
    }

    public function completedFingerprints(int $organizationId, int $projectId, int $sessionId, array $roles, array $sourceVersions): array
    {
        return [];
    }
}

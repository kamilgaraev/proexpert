<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateWorkIntent;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\SupplementaryWorkPhysicalGuard;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class EstimateComposerTest extends TestCase
{
    public function test_current_work_answer_can_propose_only_its_attached_entity_source_without_becoming_a_numeric_fact(): void
    {
        $input = $this->workSourceClaimInput();
        $source = $input->facts[0];
        self::assertSame(['fact:work-answer'], $input->claimAnswerFactIds);
        self::assertNotContains('fact:work-answer', array_column($input->facts, 'id'));
        $candidate = ['source_answer_fact_id' => 'fact:work-answer', 'entity_id' => $source['entity_id'],
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => $source['value']],
            'unit' => null, 'document_fact_ids' => [$source['id']]];
        $runs = new ComposerRoleRunMemoryRepository;
        $model = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [$candidate]]);
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
        $composer->runDetailed($input);
        self::assertSame([$candidate], $runs->lastResult->payload['claim_candidates']);
        $composer->runDetailed($input);
        self::assertSame(1, $model->calls);
        self::assertSame([], $input->derivedQuantities);
    }
    #[DataProvider('workSourceClaimContracts')]
    public function test_work_claim_capability_is_new_identity_only_and_historical_receipts_do_not_gain_it(string $old, string $active): void
    {
        $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
        $fingerprints = [];
        foreach ([$old, $active] as $contract) {
            $input = $this->workSourceClaimInput($contract);
            $fingerprints[] = $input->fingerprint();
            self::assertTrue($input->acceptsTechnologyQuestions());
            self::assertTrue($input->acceptsWorkRequirements());
            self::assertTrue($input->acceptsSourceClaims());
            self::assertSame($contract === $active, $input->acceptsWorkSourceClaims());
            self::assertSame($contract === $active ? ['fact:work-answer'] : [], $input->claimAnswerFactIds);
            $candidate = $this->workSourceClaimCandidate();
            $model = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [$candidate],
                ...($input->nativeCellContext !== [] ? ['cell_mapping_proposals' => []] : [])]);
            $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
            $outcome = $composer->runDetailed($input);
            self::assertSame($outcome, $composer->runDetailed($input));
            self::assertSame(1, $model->calls);
            $claim = $runs->claim(new AiRoleRunInput(10, 20, 30, null, null, 'estimate_session', '30', $input->snapshotToken,
                \App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole::EstimateComposer,
                'openai/gpt-6-luna', $contract, $input->fingerprint()), '11111111-1111-4111-8111-111111111111');
            self::assertSame($contract === $active ? [$candidate] : [], $claim->result->payload['claim_candidates']);
        }
        self::assertNotSame($fingerprints[0], $fingerprints[1]);
    }

    public static function workSourceClaimContracts(): iterable
    {
        yield ['estimate-composer:answers:v4', 'estimate-composer:answers:v5'];
        yield ['estimate-composer:legacy-answers:v4', 'estimate-composer:legacy-answers:v5'];
        yield ['estimate-composer:answer-cells:v3', 'estimate-composer:answer-cells:v4'];
        yield ['estimate-composer:legacy-answer-cells:v3', 'estimate-composer:legacy-answer-cells:v4'];
    }

    public function test_historical_and_future_valid_identity_replays_cannot_smuggle_a_work_answer_candidate(): void
    {
        foreach (['estimate-composer:answers:v4', 'estimate-composer:answers:v999'] as $contract) {
            $base = $this->workSourceClaimInput();
            $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, $base->facts, [], [], [], [], $contract,
                allowedWorkSections: ['equipment'], workTechnologyContext: $contract === 'estimate-composer:answers:v4' ? $base->workTechnologyContext : []);
            $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
            $identity = new AiRoleRunInput(10, 20, 30, null, null, 'estimate_session', '30', $input->snapshotToken,
                \App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole::EstimateComposer,
                'openai/gpt-6-luna', $contract, $input->fingerprint());
            $owner = '11111111-1111-4111-8111-111111111111';
            $claim = $runs->claim($identity, $owner);
            $runs->complete($claim->runId, $owner, new AiRoleRunResult(['schema_version' => 2, 'role' => 'estimate_composer',
                'work_intents' => [], 'quarantined_intents' => [], 'result_state' => 'ready',
                'claim_candidates' => [$this->workSourceClaimCandidate()]], 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
            $model = new RecordedEstimateComposerModel([]);
            try {
                (new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna'))->runDetailed($input);
                self::fail('An unsupported receipt acquired work-source privilege.');
            } catch (\RuntimeException $error) {
                self::assertSame('estimate_composer_replay_invalid', $error->getMessage());
            }
            self::assertSame(0, $model->calls);
        }
    }

    public function test_work_claim_split_keeps_context_only_with_its_sources_and_replays_without_missing_raw_answer(): void
    {
        $base = $this->workSourceClaimInput();
        $facts = [...$base->facts, ...array_map(static fn (int $id): array => ['id' => 'fact:noise-'.$id,
            'entity_id' => 'entity:noise-'.$id, 'value' => str_repeat('context ', 150)], range(1, 12))];
        $candidate = $this->workSourceClaimCandidate();
        $reference = ['learning_example_id' => 123, ...$candidate, 'interpretation_status' => 'proposed',
            'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved'];
        $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, $facts, [], [], [], [], $base->contractVersion,
            allowedWorkSections: ['equipment'], sourceClaimReferences: [$reference], workTechnologyContext: $base->workTechnologyContext);
        $planner = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerBatchPlanner(4000);
        $parts = $planner->plan($input);
        self::assertGreaterThan(1, count($parts));
        self::assertSame(1, array_sum(array_map(static fn ($part): int => count($part->sourceClaimReferences), $parts)));
        foreach ($parts as $part) {
            self::assertNotContains('fact:work-answer', array_column($part->facts, 'id'));
            self::assertSame(in_array('fact:document', array_column($part->facts, 'id'), true), in_array('fact:work-answer', $part->claimAnswerFactIds, true));
        }
        $model = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [$candidate]]);
        $composer = new RunEstimateComposer(new MultipartComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna', $planner);
        $first = $composer->runDetailed($input);
        self::assertSame($first, $composer->runDetailed($input));
        self::assertSame(count($parts), $model->calls);
        $allowance = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerAllowance::class);
        $allowance->method('partLimit')->willReturn(1);
        $blocked = $this->createMock(EstimateComposerModel::class);
        $blocked->expects(self::never())->method('compose');
        try {
            (new RunEstimateComposer(new MultipartComposerRoleRunMemoryRepository, $blocked, 'openai/gpt-6-luna', $planner, $allowance))->runDetailed($input);
            self::fail('Work context bypassed the frozen part limit.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('estimate_composer_parts_exceed_approved_plan', $error->getMessage());
        }
    }

    #[DataProvider('workSourceClaimContracts')]
    public function test_work_claim_instructions_are_new_contract_only_and_use_the_same_single_pass(string $old, string $active): void
    {
        foreach ([$old, $active] as $contract) {
            $input = $this->workSourceClaimInput($contract);
            $wire = $this->createMock(RerankWireClient::class);
            $wire->method('provider')->willReturn('timeweb');
            $wire->expects(self::once())->method('call')->willReturnCallback(static function (string $model, array $sent) use ($input, $contract, $active): array {
                if ($contract === $active) {
                    self::assertStringContainsString('active ответы из work_technology_context', $sent[0]['content']);
                } else {
                    self::assertStringNotContainsString('active ответы из work_technology_context', $sent[0]['content']);
                }
                self::assertSame($input->canonicalPayload(), json_decode($sent[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project']);

                return ['content' => json_encode(['work_intents' => [], 'claim_candidates' => [],
                    ...($input->nativeCellContext === [] ? [] : ['cell_mapping_proposals' => []])], JSON_THROW_ON_ERROR),
                    'model' => $model, 'usage_available' => true, 'input_tokens' => 100, 'output_tokens' => 10];
            });
            $usage = $this->createMock(AiUsageStore::class);
            $usage->expects(self::once())->method('record');
            $prices = $this->createMock(AiPriceSnapshotResolver::class);
            $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray([]));
            (new RunEstimateComposer(new ComposerRoleRunMemoryRepository,
                new TimewebEstimateComposerModel($wire, $usage, $prices, 'openai/gpt-6-luna', 100000, 4000, 60), 'openai/gpt-6-luna'))->runDetailed($input);
        }
    }

    public function test_only_the_exact_attached_document_claim_survives_when_a_twin_has_the_same_source_version(): void
    {
        $base = $this->workSourceClaimInput();
        $source = [...$base->facts[0], 'source_document_id' => 17];
        $twin = [...$source, 'id' => 'fact:twin', 'source_document_id' => 18];
        $entry = $base->workTechnologyContext[0];
        $entry['documents'][] = [...$entry['documents'][0], 'document_id' => 18];
        $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, [$source, $twin], [], [], [], [], $base->contractVersion,
            allowedWorkSections: ['equipment'], workTechnologyContext: [$entry]);
        $valid = $this->workSourceClaimCandidate();
        $wrong = [...$valid, 'document_fact_ids' => [$twin['id']]];
        self::assertTrue(EstimateComposerInput::sourceClaimCandidate($valid, $input->facts, $input->claimAnswerFactIds, $input->workTechnologyContext));
        self::assertFalse(EstimateComposerInput::sourceClaimCandidate($wrong, $input->facts, $input->claimAnswerFactIds, $input->workTechnologyContext));
        $model = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [$wrong, $valid]]);
        $runs = new ComposerRoleRunMemoryRepository;
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
        $result = $composer->runDetailed($input);
        self::assertSame([$valid], $runs->lastResult->payload['claim_candidates']);
        self::assertSame($result, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
    }

    public function test_factory_document_identity_requires_unambiguous_current_scoped_evidence(): void
    {
        foreach (['artifact', 'reference', 'same_document', 'missing', 'stale', 'wrong_type', 'contradiction', 'multiple', 'invalid', 'overflow'] as $mode) {
            $source = 'sha256:'.str_repeat('d', 64);
            $entity = new Entity('equipment:child', 10, 20, 30, $source, 'equipment', 'child');
            $fact = new Fact('fact:document', 10, 20, 30, $source, $entity->id, 'equipment_specification', 'Корпус AISI 316', null,
                1.0, 'document', 'confirmed', in_array($mode, ['multiple', 'same_document'], true) ? ['evidence:first', 'evidence:second'] : ['evidence:first']);
            $reference = match ($mode) {
                'invalid' => 'document:017', 'overflow' => 'document:'.str_repeat('9', 40), default => null,
            };
            $proofs = $mode === 'missing' ? [] : [new Evidence('evidence:first', 10, 20, 30,
                $mode === 'stale' ? 'sha256:'.str_repeat('f', 64) : $source,
                $mode === 'reference' ? 'artifact:unit' : ($mode === 'contradiction' ? 'document:18' : 'document:17'),
                $mode === 'wrong_type' ? 'user_input' : 'document', nativeReference: 'xlsx:Sheet1!C2',
                sourceReference: in_array($mode, ['reference', 'contradiction'], true) ? 'document:17' : $reference)];
            if (in_array($mode, ['multiple', 'same_document'], true)) {
                $proofs[] = new Evidence('evidence:second', 10, 20, 30, $source, $mode === 'multiple' ? 'document:18' : 'document:17',
                    'document', nativeReference: 'xlsx:Sheet1!C2');
            }
            $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([$entity], [$fact], $proofs, []);
            $models = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class);
            $models->method('snapshotForPlanning')->willReturn(['snapshot' => $snapshot, 'token' => str_repeat('a', 64)]);
            $models->method('decisionsForSelectedFacts')->willReturn([]);
            $catalog = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog::class);
            $catalog->method('currentWorkContexts')->willReturn($this->workSourceClaimInput()->workTechnologyContext);
            $input = (new EstimateComposerInputFactory($models, 10000, clarifications: $catalog))->capture(10, 20, 30, [], [], [], null, ['equipment'], 'universal');
            if (in_array($mode, ['artifact', 'reference', 'same_document'], true)) {
                self::assertSame(17, $input->facts[0]['source_document_id'], $mode);
            } else {
                self::assertArrayNotHasKey('source_document_id', $input->facts[0], $mode);
            }
        }
    }

    public function test_factory_selects_new_answered_family_for_eligible_work_context_without_a_legacy_answer_fact(): void
    {
        $entries = [];
        $catalog = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog::class);
        $catalog->method('currentWorkContexts')->willReturnCallback(static function () use (&$entries): array {
            return $entries;
        });
        $models = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('d', 64);
        $entity = new Entity('equipment:child', 10, 20, 30, $source, 'equipment', 'child');
        $models->saveSourceModel([$entity], [new Fact('fact:document', 10, 20, 30, $source, $entity->id,
            'equipment_specification', 'Корпус AISI 316', null, 1.0, 'document', 'confirmed', ['evidence:document'])],
            [new Evidence('evidence:document', 10, 20, 30, $source, 'document:17', 'document', nativeReference: 'xlsx:Sheet1!C2')]);
        $factory = new EstimateComposerInputFactory($models, 10000, clarifications: $catalog);
        $capture = static fn () => $factory->capture(10, 20, 30, [], [], [], null, ['equipment'], 'universal');
        self::assertSame(RunEstimateComposer::PROMPT_CONTRACT, $capture()->contractVersion);
        self::assertArrayNotHasKey('source_document_id', $capture()->facts[0]);
        $entries = $this->workSourceClaimInput()->workTechnologyContext;
        $current = $capture();
        self::assertSame(RunEstimateComposer::ANSWER_PROMPT_CONTRACT, $current->contractVersion);
        self::assertTrue($current->acceptsWorkSourceClaims());
        self::assertSame(['fact:work-answer'], $current->claimAnswerFactIds);
        self::assertSame(['fact:document'], array_column($current->facts, 'id'));
        self::assertSame(17, $current->facts[0]['source_document_id']);
        $entries[0]['status'] = 'revoked';
        self::assertSame(RunEstimateComposer::PROMPT_CONTRACT, $capture()->contractVersion);
        self::assertArrayNotHasKey('source_document_id', $capture()->facts[0]);
    }

    public function test_future_fresh_work_claim_payload_cannot_acquire_an_answered_schema(): void
    {
        $base = $this->workSourceClaimInput();
        $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, $base->facts, [], [], [], [], 'estimate-composer:answers:v999');
        $model = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [$this->workSourceClaimCandidate()]]);
        try {
            (new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna'))->runDetailed($input);
            self::fail('Future contract acquired source-claim eligibility.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('estimate_composer_result_shape_invalid', $error->getMessage());
        }
        self::assertSame(1, $model->calls);
    }

    public function test_inactive_or_unpinned_work_answers_and_wrong_entity_literal_do_not_become_candidates(): void
    {
        $base = $this->workSourceClaimInput();
        $entry = $base->workTechnologyContext[0];
        foreach ([['status' => 'revoked'], ['answer' => [...$entry['answer'], 'response' => 'leave_unresolved']],
            ['answer' => [...$entry['answer'], 'document_ids' => []]], ['documents' => []],
            ['answer' => [...$entry['answer'], 'engineering_applicability' => 'passed']],
            ['documents' => [[...$entry['documents'][0], 'status' => 'ignored']]]] as $patch) {
            $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, $base->facts, [], [], [], [], $base->contractVersion,
                allowedWorkSections: ['equipment'], workTechnologyContext: [array_replace($entry, $patch)]);
            self::assertSame([], $input->claimAnswerFactIds);
        }
        foreach ([['entity_id' => 'equipment:foreign'], ['value' => ['type' => 'string', 'data' => 'Другой текст']],
            ['document_fact_ids' => ['fact:foreign']]] as $patch) {
            self::assertFalse(EstimateComposerInput::sourceClaimCandidate(array_replace($this->workSourceClaimCandidate(), $patch),
                $base->facts, $base->claimAnswerFactIds, $base->workTechnologyContext));
        }
    }

    private function workSourceClaimCandidate(): array
    {
        return ['source_answer_fact_id' => 'fact:work-answer', 'entity_id' => 'equipment:child',
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Корпус AISI 316'],
            'unit' => null, 'document_fact_ids' => ['fact:document']];
    }

    #[DataProvider('technologyQuestionContracts')]
    public function test_question_capability_is_explicit_and_preserves_historical_semantic_and_receipt_contracts(string $historical, string $active): void
    {
        $base = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        foreach ([$historical, $active, 'estimate-composer:future:v999'] as $version) {
            $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, $base->facts, [], [], [], [], $version,
                allowedWorkSections: ['equipment']);
            if (str_contains($version, 'cells:')) {
                $input = $input->withNativeCellContext([$this->cellContext()]);
            }
            self::assertSame($version !== 'estimate-composer:future:v999', $input->acceptsWorkRequirements());
            self::assertSame($version === $active, $input->acceptsTechnologyQuestions());
            $raw = ['work_intents' => [[...$this->semanticEquipmentWork(),
                'technology_clarification' => ['question' => 'Какой способ монтажа предусмотрен?', 'reason' => 'Способ монтажа в источнике не указан.']]],
                ...($input->acceptsSourceClaims() ? ['claim_candidates' => []] : []),
                ...($input->nativeCellContext !== [] ? ['cell_mapping_proposals' => []] : [])];
            $model = new RecordedEstimateComposerModel($raw);
            $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
            $outcome = $composer->runDetailed($input);
            self::assertCount($version === $active ? 1 : 0, $outcome['intents']);
            self::assertSame($outcome, $composer->runDetailed($input));
            self::assertSame(1, $model->calls);
        }
    }

    public static function technologyQuestionContracts(): iterable
    {
        yield ['estimate-composer:v5', 'estimate-composer:v6'];
        yield ['estimate-composer:legacy:v2', 'estimate-composer:legacy:v3'];
        yield ['estimate-composer:answers:v3', 'estimate-composer:answers:v4'];
        yield ['estimate-composer:legacy-answers:v3', 'estimate-composer:legacy-answers:v4'];
        yield ['estimate-composer:cells:v2', 'estimate-composer:cells:v3'];
        yield ['estimate-composer:legacy-cells:v2', 'estimate-composer:legacy-cells:v3'];
        yield ['estimate-composer:answer-cells:v2', 'estimate-composer:answer-cells:v3'];
        yield ['estimate-composer:legacy-answer-cells:v2', 'estimate-composer:legacy-answer-cells:v3'];
    }

    #[DataProvider('technologyQuestionContracts')]
    public function test_only_question_contracts_receive_new_single_pass_instructions(string $historical, string $active): void
    {
        $base = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        foreach ([$historical, $active, 'estimate-composer:future:v999'] as $version) {
            $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, $base->facts, [], [], [], [], $version,
                allowedWorkSections: ['equipment']);
            if (str_contains($version, 'cells:')) {
                $input = $input->withNativeCellContext([$this->cellContext()]);
            }
            $wire = $this->createMock(RerankWireClient::class);
            $wire->method('provider')->willReturn('timeweb');
            $wire->expects(self::once())->method('call')->willReturnCallback(static function (string $model, array $sent) use ($input, $active, $version): array {
                self::assertCount(2, $sent);
                if ($version === $active) {
                    self::assertStringContainsString('technology_clarification', $sent[0]['content']);
                    self::assertStringContainsString('work_technology_context', $sent[0]['content']);
                } else {
                    self::assertStringNotContainsString('technology_clarification', $sent[0]['content']);
                    self::assertStringNotContainsString('work_technology_context', $sent[0]['content']);
                }
                self::assertSame($version, json_decode($sent[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project']['contract_version']);
                return ['content' => json_encode(['work_intents' => [], ...($input->acceptsSourceClaims() ? ['claim_candidates' => []] : []),
                    ...($input->nativeCellContext === [] ? [] : ['cell_mapping_proposals' => []])], JSON_THROW_ON_ERROR),
                    'model' => $model, 'usage_available' => true, 'input_tokens' => 100, 'output_tokens' => 10];
            });
            $usage = $this->createMock(AiUsageStore::class);
            $usage->expects(self::once())->method('record');
            $prices = $this->createMock(AiPriceSnapshotResolver::class);
            $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray([]));
            (new RunEstimateComposer(new ComposerRoleRunMemoryRepository,
                new TimewebEstimateComposerModel($wire, $usage, $prices, 'openai/gpt-6-luna', 100000, 4000, 60), 'openai/gpt-6-luna'))->runDetailed($input);
        }
    }

    public function test_crafted_historical_and_future_receipts_cannot_replay_a_work_question(): void
    {
        $base = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        foreach (['estimate-composer:v5', 'estimate-composer:future:v999'] as $contract) {
            $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, $base->facts, [], [], [], [], $contract,
                allowedWorkSections: ['equipment']);
            $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
            $identity = new AiRoleRunInput(10, 20, 30, null, null, 'estimate_session', '30', $input->snapshotToken,
                \App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole::EstimateComposer,
                'openai/gpt-6-luna', $contract, $input->fingerprint());
            $owner = '11111111-1111-4111-8111-111111111111';
            $claim = $runs->claim($identity, $owner);
            $runs->complete($claim->runId, $owner, new AiRoleRunResult(['schema_version' => 1, 'role' => 'estimate_composer',
                'work_intents' => [[...$this->semanticEquipmentWork(), 'technology_clarification' => [
                    'question' => 'Как выполняется монтаж этой установки?', 'reason' => 'Способ выполнения отсутствует в источнике.']]],
                'quarantined_intents' => [], 'result_state' => 'ready'], 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
            $model = new RecordedEstimateComposerModel([]);
            try {
                (new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna'))->runDetailed($input);
                self::fail('An unsupported receipt acquired the question capability.');
            } catch (\RuntimeException $error) {
                self::assertSame('estimate_composer_replay_invalid', $error->getMessage());
            }
            self::assertSame(0, $model->calls);
        }
    }

    public function test_same_composer_pass_proposes_a_work_technology_question_without_promoting_the_answer_or_quantity(): void
    {
        $input = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        $question = ['question' => 'Какой способ крепления предусмотрен для этой работы?',
            'reason' => 'В исходных данных отсутствует инструкция по монтажу.'];
        $model = new RecordedEstimateComposerModel(['work_intents' => [[...$this->semanticEquipmentWork(),
            'technology_clarification' => $question]]]);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['quarantined']);
        self::assertSame($question, $outcome['intents'][0]['technology_clarification'] ?? null);
        $work = $this->projectSemanticResult($outcome, $input)[0]['sections'][0]['work_items'][0];
        self::assertSame($question, $work['composition_intent']['technology_clarification']);
        self::assertNull($work['quantity']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
    }

    public function test_cell_proposal_is_a_closed_untrusted_receipt_and_does_not_change_work_or_quantity(): void
    {
        $row = $this->cellContext();
        $input = $this->sourceClaimInput()->withNativeCellContext([$row]);
        $proposal = ['row_id' => $row['row_id'], 'identity_cell_id' => $row['cells'][0]['cell_id'],
            'value_cell_id' => $row['cells'][1]['cell_id'], 'fact_type' => 'equipment_specification'];
        foreach ([$proposal, [...$proposal, 'value' => ['type' => 'string', 'data' => 'model']],
            [...$proposal, 'fact_type' => 'equipment_count'], [...$proposal, 'value_cell_id' => 'cell:'.str_repeat('f', 64)]] as $index => $candidate) {
            $runs = new ComposerRoleRunMemoryRepository;
            $model = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [], 'cell_mapping_proposals' => [$candidate]]);
            $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
            $outcome = $composer->runDetailed($input);
            self::assertSame($outcome, $composer->runDetailed($input));
            self::assertSame(1, $model->calls);
            self::assertSame(3, $runs->lastResult->payload['schema_version']);
            self::assertSame($index === 0 ? [$proposal] : [], $runs->lastResult->payload['cell_mapping_proposals']);
            self::assertSame([$row], $runs->lastResult->payload['cell_mapping_context']);
            self::assertSame([], $outcome['intents']);
            self::assertSame([], $input->derivedQuantities);
        }
        self::assertSame($this->sourceClaimInput()->canonicalPayload(), $input->withNativeCellContext([])->canonicalPayload());
        self::assertSame($this->sourceClaimInput()->fingerprint(), $input->withNativeCellContext([])->fingerprint());
    }

    public function test_cell_context_closed_shape_and_global_bound_are_enforced_before_any_model_call(): void
    {
        foreach ([[...$this->cellContext(), 'verified' => true], [...$this->cellContext(), 'document_id' => '1'],
            [...$this->cellContext(), 'cells' => [['cell_id' => 'cell:'.str_repeat('b', 64), 'address' => 'A2', 'raw_value' => '']]],
            [...$this->cellContext(), 'cells' => array_fill(0, 2, $this->cellContext()['cells'][0])]] as $row) {
            try {
                $this->sourceClaimInput()->withNativeCellContext([$row]);
                self::fail('Malformed server context must not enter the wire.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('estimate_composer_cell_context_invalid', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('estimate_composer_cell_context_invalid');
        $this->sourceClaimInput()->withNativeCellContext(array_fill(0, 9, $this->cellContext()));
    }

    private function cellContext(): array
    {
        return ['row_id' => 'row:'.str_repeat('a', 64), 'document_id' => 1, 'unit_id' => 2,
            'source_version' => 'sha256:'.str_repeat('a', 64), 'artifact_sha256' => 'sha256:'.str_repeat('b', 64),
            'sheet' => 'Лист', 'row' => 2, 'policy_hash' => str_repeat('c', 64), 'cells' => [
                ['cell_id' => 'cell:'.str_repeat('d', 64), 'address' => 'A2', 'raw_value' => 'Изделие'],
                ['cell_id' => 'cell:'.str_repeat('e', 64), 'address' => 'B2', 'raw_value' => 'Описание изделия'],
            ]];
    }

    public function test_answered_composer_persists_only_a_bounded_proposed_claim_and_replays_without_another_call(): void
    {
        $input = $this->sourceClaimInput();
        $candidate = ['source_answer_fact_id' => 'fact:answer', 'entity_id' => 'equipment:child',
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Корпус AISI 316'],
            'unit' => null, 'document_fact_ids' => ['fact:document']];
        $model = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [$candidate]]);
        $runs = new ComposerRoleRunMemoryRepository;
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['intents']);
        self::assertSame('estimate_composer_work_scope_unresolved', $outcome['quarantined'][0]['reason']);
        self::assertSame(2, $runs->lastResult->payload['schema_version']);
        $stored = $runs->lastResult->payload['claim_candidates'][0];
        self::assertSame($candidate, $stored);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
        self::assertSame([], $input->derivedQuantities);
    }

    private function workSourceClaimInput(string $contract = RunEstimateComposer::ANSWER_PROMPT_CONTRACT): EstimateComposerInput
    {
        $base = $this->sourceClaimInput();
        $source = [...$base->facts[1], 'source_version' => 'sha256:'.str_repeat('d', 64),
            ...(in_array($contract, EstimateComposerInput::WORK_SOURCE_CLAIM_CONTRACTS, true) ? ['source_document_id' => 17] : [])];
        $binding = ['work_key' => 'custom.installation', 'name' => 'Монтаж неизвестной установки', 'entity_id' => $source['entity_id'],
            'work_section' => 'equipment', 'unit' => null, 'requirement_key' => 'installation',
            'technology_package_candidate' => null, 'source_fact_ids' => [$source['id']]];
        $entry = ['context' => ['schema_version' => 1, 'binding' => $binding,
                'binding_hash' => hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($binding)),
                'dependencies' => [], 'question' => ['question' => 'Какой способ монтажа предусмотрен?', 'reason' => 'Не указан в источнике.']],
            'answer' => ['response' => 'other', 'other' => 'Следовать приложенному основанию как допущению.',
                'fact_id' => 'fact:work-answer', 'decision_id' => 'decision:work-answer', 'document_ids' => [17],
                'trust' => 'user_assumption', 'engineering_applicability' => 'unresolved', 'numeric_parameters_confirmed' => false],
            'documents' => [['document_id' => 17, 'status' => 'ready', 'source_version' => $source['source_version'], 'checksum_sha256' => str_repeat('d', 64)]],
            'status' => 'answered'];
        $input = new EstimateComposerInput(10, 20, 30, $base->snapshotToken, [$source], [], [], [], [], $contract,
            allowedWorkSections: ['equipment'], workTechnologyContext: [$entry]);
        return str_contains($contract, 'cells:') ? $input->withNativeCellContext([$this->cellContext()]) : $input;
    }

    private function sourceClaimInput(string $contract = 'estimate-composer:answers:v2'): EstimateComposerInput
    {
        return new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), [
            ['id' => 'fact:answer', 'entity_id' => 'project:root', 'type' => 'evaluation_answer',
                'value' => ['kind' => 'composer_answer', 'answer' => 'Нужен корпус AISI 316', 'numeric_parameters_confirmed' => false],
                'origin' => 'user_assumption', 'status' => 'confirmed', 'unit' => null,
                'context_kind' => 'composer_answer', 'context_entity_id' => 'project:root', 'answer_work_sections' => ['equipment']],
            ['id' => 'fact:document', 'entity_id' => 'equipment:child', 'entity_type' => 'equipment',
                'type' => 'equipment_specification', 'value' => 'Корпус AISI 316', 'unit' => null,
                'origin' => 'document', 'status' => 'confirmed'],
        ], [], [], [], [], $contract, allowedWorkSections: ['equipment']);
    }

    #[DataProvider('invalidSourceClaims')]
    public function test_invalid_individual_claim_does_not_become_a_durable_candidate(array $patch): void
    {
        $candidate = array_replace(['source_answer_fact_id' => 'fact:answer', 'entity_id' => 'equipment:child',
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'AISI 316'],
            'unit' => null, 'document_fact_ids' => ['fact:document']], $patch);
        $runs = new ComposerRoleRunMemoryRepository;
        $model = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [$candidate]]);
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
        $first = $composer->runDetailed($this->sourceClaimInput());
        self::assertSame([], $runs->lastResult->payload['claim_candidates']);
        self::assertSame($first, $composer->runDetailed($this->sourceClaimInput()));
        self::assertSame(1, $model->calls);
    }

    public static function invalidSourceClaims(): iterable
    {
        yield 'foreign answer' => [['source_answer_fact_id' => 'fact:foreign']];
        yield 'root entity is not target equipment' => [['entity_id' => 'project:root']];
        yield 'answer is not original source' => [['document_fact_ids' => ['fact:answer']]];
        yield 'foreign original' => [['document_fact_ids' => ['fact:foreign']]];
        yield 'multiple originals are ambiguous' => [['document_fact_ids' => ['fact:document', 'fact:other']]];
        yield 'numeric fact' => [['fact_type' => 'equipment_count']];
        yield 'quantity unit' => [['unit' => 'count']];
        yield 'empty literal' => [['value' => ['type' => 'string', 'data' => ' ']]];
        yield 'oversized literal' => [['value' => ['type' => 'string', 'data' => str_repeat('a', 501)]]];
        yield 'numeric value' => [['value' => ['type' => 'number', 'data' => 3]]];
        yield 'model attestation' => [['verified' => true]];
        yield 'model normative authority' => [['norm_id' => 123]];
        yield 'model candidate key' => [['candidate_key' => str_repeat('b', 64)]];
    }

    public function test_conflicting_candidates_for_one_answer_stay_unresolved_before_receipt_completion(): void
    {
        $candidate = ['source_answer_fact_id' => 'fact:answer', 'entity_id' => 'equipment:child',
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'AISI 316'],
            'unit' => null, 'document_fact_ids' => ['fact:document']];
        $runs = new ComposerRoleRunMemoryRepository;
        (new RunEstimateComposer($runs, new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => [
            $candidate, [...$candidate, 'value' => ['type' => 'string', 'data' => 'AISI 304']], $candidate,
        ]]), 'openai/gpt-6-luna'))->runDetailed($this->sourceClaimInput());
        self::assertSame([], $runs->lastResult->payload['claim_candidates']);
    }

    public function test_candidate_overflow_fails_the_existing_run_without_completing_a_receipt(): void
    {
        $runs = new ComposerRoleRunMemoryRepository;
        $composer = new RunEstimateComposer($runs, new RecordedEstimateComposerModel([
            'work_intents' => [], 'claim_candidates' => array_fill(0, 9, []),
        ]), 'openai/gpt-6-luna');
        try {
            $composer->runDetailed($this->sourceClaimInput());
            self::fail('An oversized envelope must fail before completion.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('estimate_composer_claim_candidates_invalid', $exception->getMessage());
        }
        self::assertNull($runs->lastResult);
        self::assertCount(1, $runs->failures);
    }

    public function test_answered_v2_does_not_reuse_the_old_work_only_v1_receipt(): void
    {
        $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
        $old = $this->sourceClaimInput('estimate-composer:answers:v1');
        $current = $this->sourceClaimInput();
        self::assertNotSame($old->fingerprint(), $current->fingerprint());
        $oldModel = new RecordedEstimateComposerModel(['work_intents' => []]);
        (new RunEstimateComposer($runs, $oldModel, 'openai/gpt-6-luna'))->runDetailed($old);
        $newModel = new RecordedEstimateComposerModel(['work_intents' => [], 'claim_candidates' => []]);
        $composer = new RunEstimateComposer($runs, $newModel, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($current);
        self::assertSame($outcome, $composer->runDetailed($current));
        self::assertSame(1, $oldModel->calls);
        self::assertSame(1, $newModel->calls);
        self::assertSame(['estimate-composer:answers:v1', 'estimate-composer:answers:v2', 'estimate-composer:answers:v2'],
            array_column($runs->inputs, 'promptContractVersion'));
    }

    public function test_source_claim_refs_are_fingerprinted_six_field_proposals_and_not_calculation_facts(): void
    {
        $base = $this->sourceClaimInput();
        $reference = ['learning_example_id' => 123, 'entity_id' => 'equipment:child', 'fact_type' => 'equipment_specification',
            'value' => ['type' => 'string', 'data' => 'AISI 316'], 'unit' => null, 'document_fact_ids' => ['fact:document'],
            'interpretation_status' => 'proposed', 'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved',
            'source_answer_fact_id' => 'fact:answer'];
        $input = $base->withSourceClaimReferences([$reference]);
        self::assertNotSame($base->fingerprint(), $input->fingerprint());
        self::assertArrayNotHasKey('source_claim_refs', $base->canonicalPayload());
        self::assertSame([array_diff_key($reference, array_flip(['interpretation_status', 'source_correspondence',
            'engineering_applicability', 'source_answer_fact_id']))], $input->canonicalPayload()['source_claim_refs']);
        self::assertSame($base->facts, $input->facts);
        self::assertSame([], $input->derivedQuantities);
        foreach (['interpretation_status' => 'verified', 'source_correspondence' => 'unresolved',
            'engineering_applicability' => 'passed', 'source_answer_fact_id' => 'fact:foreign'] as $field => $value) {
            self::assertFalse(EstimateComposerInput::sourceClaimReference([...$reference, $field => $value], $base->facts, $base->claimAnswerFactIds));
        }
    }

    #[DataProvider('sourceClaimModes')]
    public function test_actual_answered_wire_uses_one_call_for_proposed_candidates_and_honest_source_claim_refs(bool $legacy): void
    {
        $input = $this->sourceClaimInput($legacy ? RunEstimateComposer::LEGACY_ANSWER_PROMPT_CONTRACT : RunEstimateComposer::ANSWER_PROMPT_CONTRACT);
        $reference = ['learning_example_id' => 123, 'entity_id' => 'equipment:child', 'fact_type' => 'equipment_specification',
            'value' => ['type' => 'string', 'data' => 'AISI 316'], 'unit' => null, 'document_fact_ids' => ['fact:document'],
            'interpretation_status' => 'proposed', 'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved',
            'source_answer_fact_id' => 'fact:answer'];
        $input = $input->withSourceClaimReferences([$reference]);
        $wire = $this->createMock(RerankWireClient::class);
        $wire->method('provider')->willReturn('timeweb');
        $wire->expects(self::once())->method('call')->willReturnCallback(static function (string $model, array $sent) use ($input): array {
            $project = json_decode($sent[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
            self::assertSame($input->canonicalPayload(), $project);
            self::assertSame(['fact:answer'], $project['claim_answer_fact_ids']);
            self::assertCount(6, $project['source_claim_refs'][0]);
            self::assertArrayNotHasKey('source_answer_fact_id', $project['source_claim_refs'][0]);
            self::assertStringContainsString('engineering applicability остаётся unresolved', $sent[0]['content']);
            self::assertStringContainsString('не согласие пользователя', $sent[0]['content']);
            self::assertStringContainsString('не более одного кандидата на ответ и не более 8', $sent[0]['content']);
            self::assertStringContainsString('корневая сущность агрегатного ответа может отличаться', $sent[0]['content']);

            return ['content' => '{"work_intents":[],"claim_candidates":[]}', 'model' => $model,
                'usage_available' => true, 'input_tokens' => 100, 'output_tokens' => 10];
        });
        $usage = $this->createMock(AiUsageStore::class);
        $usage->expects(self::once())->method('record');
        $prices = $this->createMock(AiPriceSnapshotResolver::class);
        $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray([]));
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository,
            new TimewebEstimateComposerModel($wire, $usage, $prices, 'openai/gpt-6-luna', 100000, 4000, 60), 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame([], $outcome['intents']);
    }

    public static function sourceClaimModes(): iterable
    {
        yield 'legacy' => [true];
        yield 'universal' => [false];
    }

    #[DataProvider('declaredSemanticWorks')]
    public function test_existing_composer_owns_free_text_work_semantics_without_another_call(string $description, array $sections, array $works): void
    {
        $input = $this->declaredDescriptionInput($description, $sections);
        self::assertSame([], $input->candidates);
        self::assertSame('initial_description', $input->facts[0]['context_kind']);
        $records = array_map(static fn (array $work): array => [
            'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => $work[0], 'name' => $work[1],
            'work_section' => $work[2], 'unit' => $work[3], 'derived_quantity_id' => null,
            'source_fact_ids' => ['fact:description'], 'technology_package_candidate' => null,
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
        ], $works);
        $model = new RecordedEstimateComposerModel(['work_intents' => $records]);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['quarantined']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
        $locals = $this->projectSemanticResult($outcome, $input);
        $items = [];
        foreach ($locals as $local) {
            array_push($items, ...$local['sections'][0]['work_items']);
        }
        self::assertEqualsCanonicalizing(array_column($works, 1), array_column($items, 'name'));
        self::assertCount(count($works), array_unique(array_column($items, 'name')));
        $worksByName = array_column($works, null, 1);
        foreach ($items as $item) {
            self::assertNull($item['unit']);
            self::assertNull($item['quantity']);
            self::assertNull($item['quantity_formula']);
            self::assertNull($item['total_cost']);
            self::assertSame($worksByName[$item['name']][3], $item['composition_intent']['unit']);
            self::assertSame([['fact_id' => 'fact:description', 'role' => 'context']], $item['source_refs']);
            self::assertSame('entity:description', $item['metadata']['scope_entity_id']);
            self::assertArrayNotHasKey('quantity_key', $item['metadata']);
            self::assertArrayNotHasKey('specialization_scenario', $item);
            foreach (['materials', 'labor', 'machinery', 'other_resources', 'work_composition'] as $field) {
                self::assertSame([], $item[$field]);
            }
            self::assertTrue($item['skip_normative_matching']);
            self::assertSame('quantity_unknown', $item['pricing_blocker']);
            self::assertContains('unit_requires_confirmation', $item['validation_flags']);
            $rebound = (new \App\BusinessModules\Addons\EstimateGeneration\Quantities\CanonicalWorkItemQuantityBinder)->bind($item, []);
            self::assertArrayNotHasKey('quantity', $rebound);
            self::assertNull($rebound['unit']);
            self::assertArrayNotHasKey('quantity_key', $rebound['metadata']);
            self::assertSame('quantity_mapping_missing', $rebound['pricing_blocker']);
            $this->assertUnpricedOutlineCannotBeApplied($item);
        }
        self::assertFalse($this->semanticEvaluation($locals, $sections)['can_confirm_for_apply']);
    }

    public static function declaredSemanticWorks(): iterable
    {
        yield 'stands are equipment' => ['Монтаж стендов', ['equipment'], [['custom.stands', 'Монтаж стендов', 'equipment', 'шт']]];
        yield 'separate earthworks and landscaping' => ['Земляные работы и благоустройство территории', ['earthworks'], [
            ['custom.earthworks', 'Земляные работы', 'earthworks', 'm3'], ['custom.landscaping', 'Благоустройство территории', 'earthworks', 'м²'],
        ]];
        yield 'separate water and sewerage' => ['Водоснабжение и канализация', ['water_supply', 'sewerage'], [
            ['custom.water', 'Водоснабжение', 'water_supply', 'm'], ['custom.sewerage', 'Канализация', 'sewerage', 'm'],
        ]];
    }

    #[DataProvider('semanticWorkRequirements')]
    public function test_semantic_work_requirement_covers_only_its_kind_without_granting_engineering_or_price_authority(array $tag, bool $installationCovered): void
    {
        $input = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        $record = [...$this->semanticEquipmentWork(), ...$tag];
        $intent = EstimateWorkIntent::fromArray($record)->toArray();
        $locals = $this->projectSemanticResult(['intents' => [$intent]], $input);
        $item = $locals[0]['sections'][0]['work_items'][0];
        self::assertSame('assemble_install_ready_exhibition_stands', $item['work_key']);
        self::assertSame('entity:description', $item['metadata']['scope_entity_id']);
        self::assertSame($installationCovered ? 'installation' : null, $item['metadata']['requirement_key'] ?? null);
        foreach (['unit', 'quantity', 'quantity_formula', 'total_cost', 'price_source'] as $field) {
            self::assertNull($item[$field]);
        }
        self::assertTrue($item['skip_normative_matching']);
        self::assertSame([], $item['work_composition']);
        self::assertArrayNotHasKey('engineering_verified', $item['metadata']);
        $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([
            new Entity('entity:description', 10, 20, 30, 'sha256:'.str_repeat('d', 64), 'quantity', 'initial-description'),
        ], [], [], []);
        $result = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build([
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['equipment']],
            'local_estimates' => $locals,
        ], $snapshot);
        self::assertEqualsCanonicalizing($installationCovered ? ['equipment_specification', 'equipment_purchase', 'delivery', 'commissioning']
            : ['equipment_specification', 'equipment_purchase', 'delivery', 'installation', 'commissioning'],
            array_column($result['coverage']['missing_requirements'], 'requirement'));
        self::assertFalse($result['can_confirm_for_apply']);
        if (! $installationCovered) {
            self::assertArrayNotHasKey('requirement_key', $intent);
        }
    }

    public static function semanticWorkRequirements(): iterable
    {
        yield 'semantic installation' => [['requirement_key' => 'installation'], true];
        yield 'historical absent tag' => [[], false];
        yield 'explicit null tag' => [['requirement_key' => null], false];
    }

    #[DataProvider('canonicalWorkRequirementContradictions')]
    public function test_known_work_cannot_cover_another_kind_after_canonical_naming_and_projection(string $workKey, string $section, string $requirement): void
    {
        $input = $this->declaredDescriptionInput('Выполнить работы по выбранному разделу проекта', [$section]);
        $model = new RecordedEstimateComposerModel(['work_intents' => [[...$this->semanticEquipmentWork(),
            'work_key' => $workKey, 'work_section' => $section, 'requirement_key' => $requirement]]]);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        $locals = $this->projectSemanticResult($outcome, $input);
        if ($locals !== []) {
            self::assertSame((new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier)->canonicalName($workKey),
                $locals[0]['sections'][0]['work_items'][0]['name']);
            self::assertNotContains($requirement, array_column($this->semanticWorkEvaluation($locals, $section)['coverage']['covered_requirements'], 'requirement'));
        }
        self::assertSame([], $outcome['intents']);
        self::assertSame('estimate_work_intent_requirement_invalid', $outcome['quarantined'][0]['reason']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
    }

    #[DataProvider('canonicalWorkRequirementContradictions')]
    public function test_crafted_current_receipt_cannot_replay_a_known_work_as_another_kind(string $workKey, string $section, string $requirement): void
    {
        $input = $this->declaredDescriptionInput('Выполнить работы по выбранному разделу проекта', [$section]);
        $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
        $identity = new AiRoleRunInput(10, 20, 30, null, null, 'estimate_session', '30', $input->snapshotToken,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole::EstimateComposer,
            'openai/gpt-6-luna', $input->contractVersion, $input->fingerprint());
        $owner = '11111111-1111-4111-8111-111111111111';
        $claim = $runs->claim($identity, $owner);
        $runs->complete($claim->runId, $owner, new AiRoleRunResult(['schema_version' => 1, 'role' => 'estimate_composer',
            'work_intents' => [[...$this->semanticEquipmentWork(), 'work_key' => $workKey, 'work_section' => $section,
                'name' => (new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier)->canonicalName($workKey),
                'requirement_key' => $requirement]], 'quarantined_intents' => [], 'result_state' => 'ready'],
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
        $model = new RecordedEstimateComposerModel([]);
        try {
            (new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna'))->runDetailed($input);
            self::fail('A persisted known work was reclassified by its semantic tag.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('estimate_work_intent_requirement_invalid', $error->getMessage());
        }
        self::assertSame($identity->identityFingerprint(), $runs->inputs[1]->identityFingerprint());
        self::assertSame(0, $model->calls);
    }

    public static function canonicalWorkRequirementContradictions(): iterable
    {
        yield 'purchase is not installation' => ['equipment.purchase', 'equipment', 'installation'];
        yield 'pipe installation is not testing' => ['heating.pipe', 'heating', 'heating_testing'];
        yield 'soil export is not excavation' => ['earth.export', 'earthworks', 'excavation'];
    }

    public function test_finite_registered_work_catalog_accepts_only_its_already_mapped_requirement(): void
    {
        $classifier = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
        $registry = (new \ReflectionClass($classifier))->getConstant('WORKS');
        self::assertCount(38, $registry);
        $named = $accepted = $unverified = 0;
        foreach ($registry as $workKey => $section) {
            $canonical = \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator::workRequirement($section, $workKey);
            try {
                $name = $classifier->canonicalName($workKey);
                self::assertNotNull($name);
                $named++;
            } catch (InvalidArgumentException $error) {
                self::assertSame('estimate_composer_work_definition_unverified', $error->getMessage());
                $name = null;
                $unverified++;
            }
            foreach (\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator::workRequirementsFor($section) as $requirement) {
                $allowed = $name !== null && $requirement === $canonical;
                try {
                    $intent = EstimateWorkIntent::fromArray([...$this->semanticEquipmentWork(), 'work_key' => $workKey,
                        'work_section' => $section, 'requirement_key' => $requirement]);
                    self::assertTrue($allowed, $workKey.' was assigned '.$requirement);
                    self::assertSame($canonical, $intent->requirementKey);
                    $accepted++;
                } catch (InvalidArgumentException $error) {
                    self::assertFalse($allowed, $workKey.' lost its canonical requirement');
                    self::assertSame($name === null ? 'estimate_composer_work_definition_unverified' : 'estimate_work_intent_requirement_invalid', $error->getMessage());
                }
            }
        }
        self::assertSame(35, $named);
        self::assertSame(3, $unverified);
        self::assertSame(9, $accepted);
    }

    public function test_absent_or_null_tag_preserves_coverage_and_canonical_names_for_every_registered_work(): void
    {
        $classifier = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
        $registry = (new \ReflectionClass($classifier))->getConstant('WORKS');
        foreach ($registry as $workKey => $section) {
            foreach ([[], ['requirement_key' => null]] as $tag) {
                $input = $this->declaredDescriptionInput('Выполнить работы по выбранному разделу проекта', [$section]);
                $model = new RecordedEstimateComposerModel(['work_intents' => [[...$this->semanticEquipmentWork(),
                    'work_key' => $workKey, 'work_section' => $section, ...$tag]]]);
                $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
                $outcome = $composer->runDetailed($input);
                try {
                    $name = $classifier->canonicalName($workKey);
                } catch (InvalidArgumentException $error) {
                    self::assertSame('estimate_composer_work_definition_unverified', $error->getMessage());
                    self::assertSame([], $outcome['intents']);
                    self::assertSame('estimate_composer_work_definition_unverified', $outcome['quarantined'][0]['reason']);
                    self::assertSame(1, $model->calls);
                    continue;
                }
                self::assertSame([], $outcome['quarantined']);
                self::assertSame($name, $outcome['intents'][0]['name']);
                self::assertArrayNotHasKey('requirement_key', $outcome['intents'][0]);
                $locals = $this->projectSemanticResult($outcome, $input);
                self::assertArrayNotHasKey('requirement_key', $locals[0]['sections'][0]['work_items'][0]['metadata']);
                $result = $this->semanticWorkEvaluation($locals, $section);
                $canonical = \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator::workRequirement($section, $workKey);
                self::assertSame(in_array($canonical,
                    \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator::workRequirementsFor($section), true) ? [$canonical] : [],
                    array_column($result['coverage']['covered_requirements'], 'requirement'));
                self::assertSame($outcome, $composer->runDetailed($input));
                self::assertSame(1, $model->calls);
            }
        }
    }

    private function semanticWorkEvaluation(array $locals, string $section): array
    {
        return (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build([
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => [$section]],
            'local_estimates' => $locals,
        ], new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([
            new Entity('entity:description', 10, 20, 30, 'sha256:'.str_repeat('d', 64), 'quantity', 'initial-description'),
        ], [], [], []));
    }

    #[DataProvider('invalidSemanticWorkRequirements')]
    public function test_work_requirement_rejects_unknown_foreign_section_and_document_pairs(mixed $requirement, string $section, string $workKey = 'assemble_install_ready_exhibition_stands'): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_intent_requirement_invalid');
        EstimateWorkIntent::fromArray([...$this->semanticEquipmentWork(), 'work_key' => $workKey, 'work_section' => $section, 'requirement_key' => $requirement]);
    }

    public static function invalidSemanticWorkRequirements(): iterable
    {
        yield 'unknown requirement' => ['arbitrary_installation', 'equipment'];
        yield 'foreign section' => ['roof_covering', 'equipment'];
        yield 'wrong work section' => ['equipment_purchase', 'roofing'];
        yield 'specification requires documents' => ['equipment_specification', 'equipment'];
        yield 'test programme requires documents' => ['water_supply_test_program', 'water_supply'];
        yield 'empty key' => ['', 'equipment'];
        yield 'malformed key' => [['installation'], 'equipment'];
        yield 'canonical installation cannot become purchase' => ['equipment_purchase', 'equipment', 'equipment.install'];
        yield 'canonical finishing cannot become preparation' => ['preparation', 'finishing', 'finish.paint'];
    }

    private function semanticEquipmentWork(): array
    {
        return ['kind' => 'supplementary', 'candidate_id' => 'supplementary:stands',
            'work_key' => 'assemble_install_ready_exhibition_stands', 'name' => 'Сборка и установка готовых выставочных стендов',
            'work_section' => 'equipment', 'unit' => 'count', 'derived_quantity_id' => null,
            'entity_id' => 'entity:description', 'source_fact_ids' => ['fact:description'], 'technology_package_candidate' => null,
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
    }

    public function test_same_composer_pass_carries_custom_scope_requirement_and_replays_without_another_call(): void
    {
        $input = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        $model = new RecordedEstimateComposerModel(['work_intents' => [[...$this->semanticEquipmentWork(), 'requirement_key' => 'installation']]]);
        $runs = new ComposerRoleRunMemoryRepository;
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['quarantined']);
        self::assertSame('installation', $outcome['intents'][0]['requirement_key']);
        self::assertSame('installation', $this->projectSemanticResult($outcome, $input)[0]['sections'][0]['work_items'][0]['metadata']['requirement_key']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
        self::assertSame(1, $runs->lastResult->payload['schema_version']);
    }

    #[DataProvider('invalidSemanticWorkRequirements')]
    public function test_invalid_semantic_tag_uses_existing_quarantine_without_changing_source_or_quantity(mixed $requirement, string $section, string $workKey = 'assemble_install_ready_exhibition_stands'): void
    {
        $input = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        $model = new RecordedEstimateComposerModel(['work_intents' => [[...$this->semanticEquipmentWork(),
            'work_key' => $workKey, 'work_section' => $section, 'requirement_key' => $requirement]]]);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['intents']);
        self::assertSame('estimate_work_intent_requirement_invalid', $outcome['quarantined'][0]['reason']);
        self::assertSame('confirm_work_source', $outcome['quarantined'][0]['next_action']);
        self::assertSame(['fact:description'], $outcome['quarantined'][0]['source_fact_ids']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
        self::assertSame([], $input->derivedQuantities);
    }

    #[DataProvider('workRequirementContractVersions')]
    public function test_only_exact_new_contracts_allow_semantic_tags_and_preserve_old_cell_and_answer_families(string $old, string $active): void
    {
        $base = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        $inputs = [];
        foreach ([$old, $active, 'estimate-composer:future:v999'] as $version) {
            $input = new EstimateComposerInput($base->organizationId, $base->projectId, $base->sessionId, $base->snapshotToken,
                $base->facts, [], [], [], [], $version, allowedWorkSections: ['equipment']);
            if (str_contains($version, 'cells:')) {
                $input = $input->withNativeCellContext([$this->cellContext()]);
            }
            $inputs[] = $input;
            self::assertSame($version === $active, $input->acceptsWorkRequirements());
            $raw = ['work_intents' => [[...$this->semanticEquipmentWork(), 'requirement_key' => 'installation']],
                ...($input->acceptsSourceClaims() ? ['claim_candidates' => []] : []),
                ...($input->nativeCellContext === [] ? [] : ['cell_mapping_proposals' => []])];
            $model = new RecordedEstimateComposerModel($raw);
            $outcome = (new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna'))->runDetailed($input);
            self::assertCount($version === $active ? 1 : 0, $outcome['intents']);
            self::assertCount($version === $active ? 0 : 1, $outcome['quarantined']);
            self::assertSame(1, $model->calls);
        }
        self::assertNotSame($inputs[0]->fingerprint(), $inputs[1]->fingerprint());
        self::assertSame($inputs[0]->acceptsSourceClaims(), $inputs[1]->acceptsSourceClaims());
        if (! str_contains($old, 'cells:')) {
            self::assertSame($old, $inputs[0]->withNativeCellContext([$this->cellContext()])->withNativeCellContext([])->contractVersion);
            self::assertSame($active, $inputs[1]->withNativeCellContext([$this->cellContext()])->withNativeCellContext([])->contractVersion);
        }
    }

    public static function workRequirementContractVersions(): iterable
    {
        yield 'base' => ['estimate-composer:v4', 'estimate-composer:v5'];
        yield 'legacy' => ['estimate-composer:legacy:v1', 'estimate-composer:legacy:v2'];
        yield 'answers' => ['estimate-composer:answers:v2', 'estimate-composer:answers:v3'];
        yield 'legacy answers' => ['estimate-composer:legacy-answers:v2', 'estimate-composer:legacy-answers:v3'];
        yield 'cells' => ['estimate-composer:cells:v1', 'estimate-composer:cells:v2'];
        yield 'legacy cells' => ['estimate-composer:legacy-cells:v1', 'estimate-composer:legacy-cells:v2'];
        yield 'answer cells' => ['estimate-composer:answer-cells:v1', 'estimate-composer:answer-cells:v2'];
        yield 'legacy answer cells' => ['estimate-composer:legacy-answer-cells:v1', 'estimate-composer:legacy-answer-cells:v2'];
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['estimate-composer:v4'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['estimate-composer:future:v999'])]
    public function test_crafted_valid_identity_historical_or_future_tag_receipt_cannot_gain_semantic_privilege(string $contract): void
    {
        $base = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        $input = new EstimateComposerInput($base->organizationId, $base->projectId, $base->sessionId, $base->snapshotToken,
            $base->facts, [], [], [], [], $contract, allowedWorkSections: ['equipment']);
        $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
        $identity = new AiRoleRunInput(10, 20, 30, null, null, 'estimate_session', '30', $input->snapshotToken,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole::EstimateComposer,
            'openai/gpt-6-luna', $contract, $input->fingerprint());
        $owner = '11111111-1111-4111-8111-111111111111';
        $claim = $runs->claim($identity, $owner);
        $runs->complete($claim->runId, $owner, new AiRoleRunResult(['schema_version' => 1, 'role' => 'estimate_composer',
            'work_intents' => [[...$this->semanticEquipmentWork(), 'requirement_key' => 'installation']],
            'quarantined_intents' => [], 'result_state' => 'ready'], 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
        $model = new RecordedEstimateComposerModel([]);
        try {
            (new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna'))->runDetailed($input);
            self::fail('Unsupported persisted semantic tag was replayed.');
        } catch (\RuntimeException $error) {
            self::assertSame('estimate_composer_replay_invalid', $error->getMessage());
        }
        self::assertSame($identity->identityFingerprint(), $runs->inputs[1]->identityFingerprint());
        self::assertSame(0, $model->calls);
    }

    #[DataProvider('workRequirementContractVersions')]
    public function test_version_bump_cannot_replay_old_no_tag_receipt_and_preserves_single_part_runs(string $old, string $active): void
    {
        $base = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
        $allowance = new class implements \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerAllowance
        {
            public function partLimit(EstimateComposerInput $input): ?int { return 1; }
        };
        $oldResult = null;
        foreach ([$old, $active] as $version) {
            $input = new EstimateComposerInput($base->organizationId, $base->projectId, $base->sessionId, $base->snapshotToken,
                $base->facts, [], [], [], [], $version, allowedWorkSections: ['equipment']);
            if (str_contains($version, 'cells:')) {
                $input = $input->withNativeCellContext([$this->cellContext()]);
            }
            $record = [...$this->semanticEquipmentWork(), ...($version === $active ? ['requirement_key' => 'installation'] : [])];
            $model = new RecordedEstimateComposerModel(['work_intents' => [$record],
                ...($input->acceptsSourceClaims() ? ['claim_candidates' => []] : []),
                ...($input->nativeCellContext === [] ? [] : ['cell_mapping_proposals' => []])]);
            $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna', allowance: $allowance);
            $result = $composer->runDetailed($input);
            self::assertSame([], $result['quarantined']);
            self::assertSame($result, $composer->runDetailed($input));
            self::assertSame(1, $model->calls);
            if ($version === $old) {
                $oldResult = $result;
                self::assertArrayNotHasKey('requirement_key', $result['intents'][0]);
            } else {
                self::assertSame('installation', $result['intents'][0]['requirement_key']);
                self::assertNotSame($oldResult, $result);
            }
        }
        self::assertSame([$old, $old, $active, $active], array_column($runs->inputs, 'promptContractVersion'));
        self::assertNotSame($runs->inputs[0]->identityFingerprint(), $runs->inputs[2]->identityFingerprint());
    }

    #[DataProvider('workRequirementContractVersions')]
    public function test_only_new_contract_wire_receives_semantic_catalog_in_the_existing_single_call(string $old, string $active): void
    {
        $base = $this->declaredDescriptionInput('Сборка и установка готовых выставочных стендов', ['equipment']);
        foreach ([$old, $active, 'estimate-composer:future:v999'] as $version) {
            $input = new EstimateComposerInput($base->organizationId, $base->projectId, $base->sessionId, $base->snapshotToken,
                $base->facts, [], [], [], [], $version, allowedWorkSections: ['equipment']);
            if (str_contains($version, 'cells:')) {
                $input = $input->withNativeCellContext([$this->cellContext()]);
            }
            $wire = $this->createMock(RerankWireClient::class);
            $wire->method('provider')->willReturn('timeweb');
            $wire->expects(self::once())->method('call')->willReturnCallback(static function (string $model, array $sent) use ($input, $version, $active): array {
                self::assertCount(2, $sent);
                self::assertSame($version, json_decode($sent[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project']['contract_version']);
                if ($version === $active) {
                    self::assertStringContainsString('nullable requirement_key', $sent[0]['content']);
                    self::assertStringContainsString('"equipment":["equipment_purchase","delivery","installation","commissioning"]', $sent[0]['content']);
                    self::assertStringContainsString('не подтверждает инженерную применимость', $sent[0]['content']);
                } else {
                    self::assertStringNotContainsString('requirement_key', $sent[0]['content']);
                    self::assertStringNotContainsString('Допустимые пары:', $sent[0]['content']);
                    if ($version === 'estimate-composer:v4') {
                        self::assertSame('f3624d41635d297be7a1eb5445115e2ad67dbdac0685a27b0d2400d610e9432a', hash('sha256', $sent[0]['content']));
                    } elseif ($version === 'estimate-composer:legacy:v1') {
                        self::assertSame('b4636d0a89d9a10e2efef1f2becf7a1f32f0d096984f2986c7cd7dc08798291b', hash('sha256', $sent[0]['content']));
                    }
                }
                return ['content' => json_encode(['work_intents' => [],
                    ...($input->acceptsSourceClaims() ? ['claim_candidates' => []] : []),
                    ...($input->nativeCellContext === [] ? [] : ['cell_mapping_proposals' => []])], JSON_THROW_ON_ERROR),
                    'model' => $model, 'usage_available' => true, 'input_tokens' => 100, 'output_tokens' => 10];
            });
            $usage = $this->createMock(AiUsageStore::class);
            $usage->expects(self::once())->method('record');
            $prices = $this->createMock(AiPriceSnapshotResolver::class);
            $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray([]));
            (new RunEstimateComposer(new ComposerRoleRunMemoryRepository,
                new TimewebEstimateComposerModel($wire, $usage, $prices, 'openai/gpt-6-luna', 100000, 4000, 60), 'openai/gpt-6-luna'))->runDetailed($input);
        }
    }

    public function test_preserved_legacy_description_does_not_declare_a_work_section(): void
    {
        $input = $this->declaredDescriptionInput('Монтаж стендов', []);
        $model = new RecordedEstimateComposerModel(['work_intents' => [[
            'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.stands', 'name' => 'Монтаж стендов',
            'work_section' => 'equipment', 'unit' => 'шт', 'derived_quantity_id' => null,
            'source_fact_ids' => ['fact:description'], 'technology_package_candidate' => null,
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
        ]]]);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['intents']);
        self::assertCount(1, $outcome['quarantined']);
        self::assertSame('estimate_composer_work_section_not_selected', $outcome['quarantined'][0]['reason']);
        self::assertSame([], $this->projectSemanticResult($outcome, $input));
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
    }

    public function test_authoritative_legacy_description_allows_semantic_sections_without_declaring_user_scope(): void
    {
        $input = $this->declaredDescriptionInput('Монтаж стендов', [], 'legacy');
        self::assertNotEmpty($input->allowedWorkSections);
        self::assertArrayNotHasKey('declared_work_sections', $input->facts[0]);
        self::assertSame($input->allowedWorkSections, $input->facts[0]['semantic_work_sections']);
        self::assertNotSame($this->declaredDescriptionInput('Монтаж стендов', [])->fingerprint(), $input->fingerprint());
        $model = new RecordedEstimateComposerModel(['work_intents' => [[
            'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.stands', 'name' => 'Монтаж стендов',
            'work_section' => 'equipment', 'unit' => 'шт', 'derived_quantity_id' => null,
            'source_fact_ids' => ['fact:description'], 'technology_package_candidate' => null,
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
        ]]]);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['quarantined']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
        $locals = $this->projectSemanticResult($outcome, $input);
        $item = $locals[0]['sections'][0]['work_items'][0];
        self::assertSame('Монтаж стендов', $item['name']);
        self::assertNull($item['unit']);
        self::assertNull($item['quantity']);
        self::assertNull($item['total_cost']);
        self::assertSame([], $item['work_composition']);
        self::assertSame([['fact_id' => 'fact:description', 'role' => 'context']], $item['source_refs']);
    }

    public function test_legacy_ambiguous_composition_uses_actual_source_only_validation_without_audit(): void
    {
        $input = $this->declaredDescriptionInput('Требуется уточнение неизвестной технологии', [], 'legacy');
        $model = new RecordedEstimateComposerModel(['work_intents' => []]);
        $outcome = (new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna'))->runDetailed($input);
        $draft = $this->validateUnresolvedComposition($outcome, true);
        self::assertTrue($draft['source_info_only']);
        self::assertSame([], $draft['local_estimates']);
        self::assertNull($draft['evaluation_result']);
        self::assertFalse($draft['is_complete']);
        self::assertSame('source_info_required', $draft['stage6_status']);
        self::assertSame(1, $model->calls);
    }

    public function test_authoritative_universal_policy_preserves_payload_and_replay_identity(): void
    {
        $original = $this->declaredDescriptionInput('Монтаж стендов', ['equipment']);
        $explicit = $this->declaredDescriptionInput('Монтаж стендов', ['equipment'], 'universal');
        self::assertSame($original->canonicalPayload(), $explicit->canonicalPayload());
        self::assertSame($original->fingerprint(), $explicit->fingerprint());
        self::assertSame(['equipment'], $explicit->facts[0]['declared_work_sections']);
        self::assertArrayNotHasKey('semantic_work_sections', $explicit->facts[0]);
    }

    #[DataProvider('invalidLegacyDescriptions')]
    public function test_legacy_semantic_vocabulary_requires_genuine_description_provenance(array $overrides): void
    {
        $input = $this->declaredDescriptionInput('Монтаж стендов', [], 'legacy', $overrides);
        self::assertSame([], $input->allowedWorkSections);
        self::assertArrayNotHasKey('semantic_work_sections', $input->facts[0]);
        self::assertSame(RunEstimateComposer::PROMPT_CONTRACT, $input->contractVersion);
    }

    public static function invalidLegacyDescriptions(): iterable
    {
        yield 'document fact' => [['origin' => 'document']];
        yield 'model inference' => [['origin' => 'ai_inference']];
        yield 'another producer' => [['producer' => 'drawing_analyzer']];
        yield 'another native reference' => [['reference' => 'another.field']];
        yield 'document evidence' => [['source_type' => 'project_document']];
        yield 'stale evidence version' => [['proof_version' => 'sha256:'.str_repeat('e', 64)]];
        yield 'numeric answer' => [['value' => ['numeric_parameters_confirmed' => true]]];
        yield 'other answer with forged marker' => [['value' => ['kind' => 'other', 'semantic_work_sections' => EstimateComposerInput::WORK_SECTIONS]]];
    }

    #[DataProvider('legacyDocumentWorkKeys')]
    public function test_legacy_description_does_not_replace_or_promote_independent_document_work_sources(string $key, bool $supported): void
    {
        $input = $this->declaredDescriptionInput('Работы уточняются по проекту', [], 'legacy', ['with_document' => true]);
        $model = new RecordedEstimateComposerModel(['work_intents' => [[
            'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => $key, 'name' => 'Работа по проекту',
            'work_section' => 'roofing', 'unit' => 'м²', 'derived_quantity_id' => null,
            'source_fact_ids' => ['fact:roof'], 'technology_package_candidate' => null,
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
        ]]]);
        $outcome = (new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna'))->runDetailed($input);
        if ($supported) {
            self::assertSame([], $outcome['quarantined']);
            self::assertSame(['fact:roof'], $outcome['intents'][0]['source_fact_ids']);
            self::assertSame('entity:roof', $outcome['intents'][0]['entity_id']);
        } else {
            self::assertSame([], $outcome['intents']);
            self::assertSame('estimate_composer_work_definition_unverified', $outcome['quarantined'][0]['reason']);
        }
    }

    public static function legacyDocumentWorkKeys(): iterable
    {
        yield 'existing definition uses its own source' => ['roof_underlay', true];
        yield 'generic document work does not borrow semantic request privilege' => ['custom.document_work', false];
    }

    private function assertUnpricedOutlineCannotBeApplied(array $item): void
    {
        $previous = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $container = new \Illuminate\Container\Container;
        $container->instance('validator', new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'ru')));
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $projector = (new \ReflectionClass(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ConfirmedEvaluationDraftProjector::class))->newInstanceWithoutConstructor();
        try {
            foreach ([null, '3'] as $quantity) {
                $current = [...$item, 'quantity' => $quantity, 'unit' => $quantity === null ? null : 'count'];
                $selected = ['scenario' => ['positions' => [['key' => $item['key'], 'quantity' => '3', 'money_breakdown' => [], 'price_snapshot' => []]]]];
                try {
                    $projector->projectVerifiedSelection(['local_estimates' => [['sections' => [['work_items' => [$current]]]]]], $selected, ['scenario_id' => 'base']);
                    self::fail('An unpriced semantic outline was applied.');
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    self::assertArrayHasKey('draft', $exception->errors());
                }
            }
        } finally {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($previous);
        }
    }

    public function test_ambiguous_semantics_with_empty_candidates_uses_the_existing_request_info_blocker(): void
    {
        $input = $this->declaredDescriptionInput('Требуется уточнить неизвестную технологию', ['equipment']);
        $model = new RecordedEstimateComposerModel(['work_intents' => []]);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['intents']);
        self::assertSame('estimate_composer_work_scope_unresolved', $outcome['quarantined'][0]['reason']);
        self::assertSame('provide_work_scope', $outcome['quarantined'][0]['next_action']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
        $draft = $this->validateUnresolvedComposition($outcome);
        self::assertTrue($draft['source_info_only']);
        self::assertSame('source_info_required', $draft['stage6_status']);
        self::assertSame([], $draft['local_estimates']);
        self::assertNull($draft['evaluation_result']);
        self::assertFalse($draft['is_complete']);
        self::assertSame($outcome['quarantined'], $draft['estimate_composition']['request_info']);
        self::assertSame(1, $model->calls);
    }

    private function validateUnresolvedComposition(array $outcome, bool $legacy = false): array
    {
        $base = 'sha256:'.str_repeat('a', 64);
        $attempt = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $graph = \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph::standard();
        $results = new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory(
            new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore, $graph);
        $build = \App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage::BuildDraft;
        $validate = \App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage::ValidateDraft;
        $dependencies = array_fill_keys(array_map(static fn ($stage): string => $stage->value, $graph->get($build)->dependencies), $base);
        $draft = ['generation_contract' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT,
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['equipment']],
            'local_estimates' => [], 'estimate_composition' => ['request_info' => $outcome['quarantined']]];
        if ($legacy) {
            unset($draft['generation_contract'], $draft['evaluation_policy']);
            $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->build($draft);
        }
        $built = $results->make(new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext(30, 10, 20, 1, $base, 'generating',
            generationAttemptId: $attempt, baseInputVersion: $base, stage: $build, dependencyVersions: $dependencies), $build, ['draft' => $draft]);
        $context = new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext(30, 10, 20, 1, $base, 'generating',
            priorOutputs: new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs(
                [$build->value => $built->output], [$build->value => ['draft' => $draft]]),
            generationAttemptId: $attempt, baseInputVersion: $base, stage: $validate, dependencyVersions: [$build->value => $built->output->version]);
        $stage = new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ValidateDraftStage(
            new \App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService,
            new \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector, $results,
            (new \ReflectionClass(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory::class))->newInstanceWithoutConstructor());

        return $stage->execute($context)->transientData['draft'];
    }

    #[DataProvider('wholeCompositionFailures')]
    public function test_provider_or_whole_envelope_failure_remains_a_failed_run(bool $unavailable): void
    {
        $runs = new ComposerRoleRunMemoryRepository;
        $model = new class($unavailable) implements EstimateComposerModel
        {
            public int $calls = 0;

            public function __construct(private bool $unavailable) {}

            public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
            {
                $this->calls++;
                $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
                if ($this->unavailable) {
                    throw new \RuntimeException('provider_unavailable');
                }

                return ['invalid_envelope' => []];
            }
        };
        try {
            (new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna'))->runDetailed($this->declaredDescriptionInput('Монтаж стендов', ['equipment']));
            self::fail('A provider or envelope failure became successful composition.');
        } catch (\RuntimeException|InvalidArgumentException $exception) {
            self::assertSame($unavailable ? 'provider_unavailable' : 'estimate_composer_result_shape_invalid', $exception->getMessage());
        }
        self::assertSame(1, $model->calls);
        self::assertCount(1, $runs->failures);
        self::assertSame('estimate_composer_failed', $runs->failures[0]->code);
        self::assertNull($runs->lastResult);
    }

    public static function wholeCompositionFailures(): iterable
    {
        yield 'provider unavailable' => [true];
        yield 'whole malformed envelope' => [false];
    }

    private function declaredDescriptionInput(string $description, array $sections, ?string $mode = null, array $overrides = []): EstimateComposerInput
    {
        $models = new InMemoryProjectModelRepository;
        $version = 'sha256:'.str_repeat('d', 64);
        $entity = new Entity('entity:description', 10, 20, 30, $version, 'quantity', 'initial-description');
        $proof = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence(
            'evidence:description', 10, 20, 30, $overrides['proof_version'] ?? $version, 'input:30', $overrides['source_type'] ?? 'user_input',
            nativeReference: $overrides['reference'] ?? 'input_payload.description', producerName: $overrides['producer'] ?? 'user_input_normalizer');
        $fact = new Fact('fact:description', 10, 20, 30, $version, $entity->id, 'evaluation_answer',
            [...['kind' => 'initial_description', 'description' => $description, 'numeric_parameters_confirmed' => false], ...($overrides['value'] ?? [])],
            null, 0.0, $overrides['origin'] ?? 'user_input', 'confirmed', [$proof->id]);
        $models->saveSourceModel([$entity], [$fact], [$proof]);
        $models->applyDecision(new Decision('decision:description', 10, 20, 30, $version, 'fact', $fact->id, $fact->id,
            'user', 'actor:7', 'Описание пользователя', 1, [$proof->id]), $fact);
        if (($overrides['with_document'] ?? false) === true) {
            $models->saveSourceModel([new Entity('entity:roof', 10, 20, 30, $version, 'quantity', 'roof', ['semantic_type' => 'roof'])],
                [new Fact('fact:roof', 10, 20, 30, $version, 'entity:roof', 'roof_type', 'flat', null, 0.95, 'document', 'confirmed', ['evidence:roof'])],
                [new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence('evidence:roof', 10, 20, 30, $version, 'document:1', 'project_document', nativeReference: 'page:1')]);
        }

        return (new EstimateComposerInputFactory($models, 10000))->capture(10, 20, 30, [], [], [], null, $sections, $mode);
    }

    private function projectSemanticResult(array $outcome, EstimateComposerInput $input): array
    {
        $application = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $previous = ['app' => [\Illuminate\Support\Facades\App::class, \Illuminate\Support\Facades\App::getFacadeRoot()],
            'config' => [\Illuminate\Support\Facades\Config::class, \Illuminate\Support\Facades\Config::getFacadeRoot()],
            'translator' => [\Illuminate\Support\Facades\Lang::class, \Illuminate\Support\Facades\Lang::getFacadeRoot()]];
        \Illuminate\Support\Facades\App::swap(new class
        {
            public function getLocale(): string { return 'ru'; }
        });
        \Illuminate\Support\Facades\Config::swap(new \Illuminate\Config\Repository(['app' => ['fallback_locale' => 'ru']]));
        $loader = new \Illuminate\Translation\ArrayLoader;
        $loader->addMessages('ru', 'estimate_generation', ['composition' => ['supplementary_title' => 'Дополнительные работы', 'supplementary_section' => 'Работы']]);
        \Illuminate\Support\Facades\Lang::swap(new \Illuminate\Translation\Translator($loader, 'ru'));
        try {
            return (new EstimateCompositionProjector)->apply([], $outcome['intents'], $input->derivedQuantities, $input->facts);
        } finally {
            foreach ($previous as $accessor => [$facade, $value]) {
                if ($value !== null) { $facade::swap($value); } else {
                    \Illuminate\Support\Facades\Facade::clearResolvedInstance($accessor);
                    $application?->forgetInstance($accessor);
                }
            }
        }
    }

    private function semanticEvaluation(array $locals, array $sections, array $requests = []): array
    {
        return (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build([
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => $sections],
            'local_estimates' => $locals, 'estimate_composition' => ['request_info' => $requests],
        ], new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([], [], [], []));
    }

    public function test_registered_supplementary_work_name_is_owned_by_the_server_on_fresh_and_saved_results(): void
    {
        $input = $this->input(allowedWorkSections: ['roofing']);
        $payload = $this->validResult($input);
        $payload['work_intents'][] = [...$this->unverifiedSupplementaryWork(),
            'work_key' => 'roof_underlay', 'name' => 'Монтаж труб отопления'];
        $runs = new ComposerRoleRunMemoryRepository;
        $model = new RecordedEstimateComposerModel($payload);
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
        $first = $composer->runDetailed($input);
        $works = array_values(array_filter($first['intents'], static fn ($work): bool => $work['work_key'] === 'roof_underlay'));
        self::assertCount(1, $works);
        self::assertSame('Устройство подкладочного слоя кровли', $works[0]['name']);
        self::assertSame($first, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);

        $legacy = $this->validResult($input)['work_intents'];
        $legacy[] = [...$payload['work_intents'][array_key_last($payload['work_intents'])],
            'candidate_id' => 'supplementary:legacy', 'entity_id' => 'entity:roof'];
        $stored = new ComposerRoleRunMemoryRepository;
        $stored->complete(1, '11111111-1111-4111-8111-111111111111', new AiRoleRunResult([
            'work_intents' => $legacy, 'quarantined_intents' => [],
        ], 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
        $noCalls = new RecordedEstimateComposerModel([]);
        $replayed = (new RunEstimateComposer($stored, $noCalls, 'openai/gpt-6-luna'))->runDetailed($input);
        $works = array_values(array_filter($replayed['intents'], static fn ($work): bool => $work['work_key'] === 'roof_underlay'));
        self::assertSame('Устройство подкладочного слоя кровли', $works[0]['name']);
        self::assertSame(array_column($input->candidates, 'candidate_id'), array_column(array_values(array_filter(
            $replayed['intents'], static fn ($work): bool => $work['kind'] === 'existing',
        )), 'candidate_id'));
        self::assertSame(0, $noCalls->calls);
    }

    public function test_quantity_aliases_cannot_name_a_supplementary_work_even_with_a_confirmed_source(): void
    {
        $input = $this->input(allowedWorkSections: ['roofing']);
        foreach (['roof.area', 'roof.flat_area'] as $key) {
            $payload = $this->validResult($input);
            $payload['work_intents'][] = [...$this->unverifiedSupplementaryWork(), 'work_key' => $key];
            $model = new RecordedEstimateComposerModel($payload);
            $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
            $first = $composer->runDetailed($input);
            self::assertNotContains($key, array_column($first['intents'], 'work_key'));
            self::assertSame('estimate_composer_work_definition_unverified', $first['quarantined'][0]['reason']);
            self::assertSame('Монтаж труб отопления', $first['quarantined'][0]['work_name']);
            self::assertSame($first, $composer->runDetailed($input));
            self::assertSame(1, $model->calls);
        }
    }

    public function test_unknown_work_with_a_forged_section_stays_request_info_and_replays_without_paid_calls(): void
    {
        $input = $this->input(allowedWorkSections: ['roofing']);
        $payload = $this->validResult($input);
        $payload['work_intents'][] = $this->unverifiedSupplementaryWork();
        $model = new RecordedEstimateComposerModel($payload);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertNotContains('custom.installation', array_column($outcome['intents'], 'work_key'));
        self::assertSame('estimate_composer_work_definition_unverified', $outcome['quarantined'][0]['reason']);
        self::assertSame('Монтаж труб отопления', $outcome['quarantined'][0]['work_name']);
        self::assertSame(['fact:roof'], $outcome['quarantined'][0]['source_fact_ids']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
    }

    public function test_a_stored_unverified_work_is_rechecked_locally_without_changing_valid_candidate_identity(): void
    {
        $input = $this->input(allowedWorkSections: ['roofing']);
        $stored = $this->validResult($input)['work_intents'];
        $stored[] = [...$this->unverifiedSupplementaryWork(), 'candidate_id' => 'supplementary:legacy', 'entity_id' => 'entity:roof'];
        $runs = new ComposerRoleRunMemoryRepository;
        $runs->complete(1, '11111111-1111-4111-8111-111111111111', new AiRoleRunResult([
            'work_intents' => $stored, 'quarantined_intents' => [],
        ], 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
        $model = new RecordedEstimateComposerModel([]);
        $outcome = (new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna'))->runDetailed($input);
        self::assertNotContains('custom.installation', array_column($outcome['intents'], 'work_key'));
        self::assertSame(array_column($input->candidates, 'candidate_id'), array_column($outcome['intents'], 'candidate_id'));
        self::assertSame('estimate_composer_work_definition_unverified', $outcome['quarantined'][0]['reason']);
        self::assertSame(0, $model->calls);
    }

    private function unverifiedSupplementaryWork(): array
    {
        return ['kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.installation',
            'name' => 'Монтаж труб отопления', 'unit' => 'm', 'work_section' => 'roofing',
            'derived_quantity_id' => null, 'source_fact_ids' => ['fact:roof'], 'technology_package_candidate' => null,
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
    }

    public function test_irrelevant_roof_fact_becomes_request_info_and_replays_without_another_model_call(): void
    {
        $input = $this->input();
        $payload = $this->validResult($input);
        $payload['work_intents'][] = ['kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'heating.pipe',
            'name' => 'Монтаж труб отопления', 'derived_quantity_id' => null, 'source_fact_ids' => ['fact:roof'],
            'technology_package_candidate' => null, 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
        $model = new RecordedEstimateComposerModel($payload);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertNotContains('heating.pipe', array_column($outcome['intents'], 'work_key'));
        self::assertSame('estimate_composer_work_source_unrelated', $outcome['quarantined'][0]['reason']);
        self::assertSame('Монтаж труб отопления', $outcome['quarantined'][0]['work_name']);
        self::assertSame(['fact:roof'], $outcome['quarantined'][0]['source_fact_ids']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
    }

    public function test_composer_covers_each_deterministic_work_once_and_replays_without_second_model_call(): void
    {
        $input = $this->input();
        $runs = new ComposerRoleRunMemoryRepository;
        $model = new RecordedEstimateComposerModel($this->validResult($input));
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-5-mini');

        $first = $composer->run($input);
        $second = $composer->run($input);

        self::assertSame($first, $second);
        self::assertCount(count($input->candidates), $first);
        self::assertSame(array_column($input->candidates, 'candidate_id'), array_column($first, 'candidate_id'));
        self::assertSame(1, $model->calls);
        self::assertSame('estimate_composer', $runs->inputs[0]->role->value);
        self::assertSame($input->snapshotToken, $runs->inputs[0]->subjectVersion);
        self::assertSame($input->fingerprint(), $runs->inputs[0]->inputFingerprint);
    }

    public function test_composer_adds_a_fact_bounded_supplementary_work_without_price_or_double_count(): void
    {
        $input = $this->input();
        $result = $this->validResult($input);
        $result['work_intents'][] = [
            'kind' => 'supplementary',
            'candidate_id' => 'supplementary:roof-safety',
            'work_key' => 'roof_underlay',
            'name' => 'Устройство подкладочного слоя кровли',
            'derived_quantity_id' => 'quantity:roof',
            'source_fact_ids' => ['fact:roof'],
            'technology_package_candidate' => null,
            'assumptions' => [],
            'exclusions' => [],
            'missing_document_recommendations' => [],
        ];

        $model = new RecordedEstimateComposerModel($result);
        $composer = new RunEstimateComposer(
            new ComposerRoleRunMemoryRepository,
            $model,
            'openai/gpt-5-mini',
        );
        $intents = $composer->run($input);
        self::assertSame($intents, $composer->run($input));
        self::assertSame(1, $model->calls);
        $intent = $intents[array_key_last($intents)];
        self::assertSame('supplementary', $intent['kind']);
        self::assertStringStartsWith('supplementary:', $intent['candidate_id']);
        self::assertNotSame('supplementary:roof-safety', $intent['candidate_id']);
        self::assertSame('roof_underlay', $intent['work_key']);
        self::assertSame('quantity:roof', $intent['derived_quantity_id']);
        self::assertArrayNotHasKey('unit_price', $intent);
        self::assertSame(['fact:roof'], $intent['source_fact_ids']);
    }

    #[DataProvider('invalidResultProvider')]
    public function test_composer_projects_server_owned_coverage_and_quarantines_only_bad_content(
        callable $mutate,
        bool $expectsQuarantine,
    ): void {
        $input = $this->input();
        $result = $mutate($this->validResult($input));
        $runs = new ComposerRoleRunMemoryRepository;
        $composer = new RunEstimateComposer(
            $runs,
            new RecordedEstimateComposerModel($result),
            'openai/gpt-5-mini',
        );

        $intents = $composer->run($input);

        self::assertCount(count($input->candidates), $intents);
        self::assertSame(array_column($input->candidates, 'candidate_id'), array_column($intents, 'candidate_id'));
        self::assertSame(
            $expectsQuarantine ? 'partial' : 'ready',
            $runs->lastResult?->payload['result_state'],
        );
    }

    public static function invalidResultProvider(): iterable
    {
        yield 'suppressed candidate' => [
            static function (array $result): array {
                array_pop($result['work_intents']);

                return $result;
            },
            false,
        ];
        yield 'duplicate candidate' => [
            static function (array $result): array {
                $result['work_intents'][1]['candidate_id'] = $result['work_intents'][0]['candidate_id'];

                return $result;
            },
            true,
        ];
        yield 'invented fact' => [
            static function (array $result): array {
                $result['work_intents'][0]['source_fact_ids'] = ['fact:invented'];

                return $result;
            },
            false,
        ];
        yield 'wrong technology package' => [
            static function (array $result): array {
                $result['work_intents'][9]['technology_package_candidate'] = 'package:invented';

                return $result;
            },
            false,
        ];
        yield 'raw provider assumption' => [
            static function (array $result): array {
                $result['work_intents'][0]['assumptions'] = ['Provider payload is valid.'];

                return $result;
            },
            true,
        ];
        yield 'english supplementary work name' => [
            static function (array $result): array {
                $result['work_intents'][] = [
                    'kind' => 'supplementary',
                    'candidate_id' => 'supplementary:roof-barrier',
                    'work_key' => 'roof_safety_barrier',
                    'name' => 'Install roof safety barrier',
                    'derived_quantity_id' => 'quantity:roof',
                    'source_fact_ids' => ['fact:roof'],
                    'technology_package_candidate' => null,
                    'assumptions' => [],
                    'exclusions' => [],
                    'missing_document_recommendations' => [],
                ];

                return $result;
            },
            true,
        ];
    }

    public function test_provider_price_copy_is_ignored_and_never_becomes_authoritative(): void
    {
        $input = $this->input();
        $result = $this->validResult($input);
        $result['work_intents'][0]['price'] = '999999.99';

        $intents = (new RunEstimateComposer(
            new ComposerRoleRunMemoryRepository,
            new RecordedEstimateComposerModel($result),
            'openai/gpt-5-mini',
        ))->run($input);

        self::assertArrayNotHasKey('price', $intents[0]);
        self::assertSame($input->candidates[0]['candidate_id'], $intents[0]['candidate_id']);
    }

    public function test_composer_answer_numbers_and_model_prices_cannot_replace_an_eligible_server_quantity(): void
    {
        $original = $this->declaredDescriptionInput('Монтаж стендов', ['equipment']);
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), [...$original->facts, [
            'id' => 'fact:answer', 'entity_id' => 'entity:answer', 'entity_type' => 'quantity', 'type' => 'evaluation_answer',
            'value' => ['kind' => 'composer_answer', 'answer' => 'Монтаж 3 стендов по 999 рублей, применить норму 123.',
                'question_key' => 'composer_source_'.str_repeat('b', 40), 'numeric_parameters_confirmed' => false],
            'unit' => null, 'origin' => 'user_assumption', 'status' => 'confirmed', 'evidence_ids' => ['evidence:answer'],
            'context_kind' => 'composer_answer', 'context_entity_id' => 'entity:description', 'answer_work_sections' => ['equipment'],
        ]], [], [], [], [], RunEstimateComposer::ANSWER_PROMPT_CONTRACT, allowedWorkSections: ['equipment']);
        $model = new RecordedEstimateComposerModel(['work_intents' => [[
            'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.stands', 'name' => 'Монтаж стендов',
            'work_section' => 'equipment', 'unit' => 'count', 'derived_quantity_id' => null, 'source_fact_ids' => ['fact:answer'],
            'technology_package_candidate' => null, 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
            'quantity' => '3', 'unit_price' => '999', 'total_cost' => '2997', 'norm_id' => 123,
        ]], 'claim_candidates' => []]);
        $composer = new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame([], $outcome['quarantined']);
        self::assertCount(1, $outcome['intents']);
        self::assertSame($outcome, $composer->runDetailed($input));
        self::assertSame(1, $model->calls);
        $locals = $this->projectSemanticResult($outcome, $input);
        $item = $locals[0]['sections'][0]['work_items'][0];
        self::assertSame('Монтаж стендов', $item['name']);
        self::assertNull($item['quantity']);
        self::assertNull($item['unit']);
        self::assertNull($item['total_cost']);
        self::assertArrayNotHasKey('unit_price', $item);
        self::assertArrayNotHasKey('norm_id', $item);
        self::assertArrayNotHasKey('quantity_evidence', $item);
        self::assertSame([], $item['materials']);
        self::assertSame([], $item['labor']);
        self::assertTrue($item['skip_normative_matching']);
        self::assertSame('quantity_unknown', $item['pricing_blocker']);
    }

    public function test_selected_answer_requires_matching_server_metadata_and_never_falls_back_to_the_root(): void
    {
        $facts = $this->sourceClaimInput()->facts;
        $facts[0]['value']['target_entity_id'] = 'equipment:child';
        $facts[0]['related_entity_ids'] = ['equipment:child'];
        $facts[0]['evidence_ids'] = ['evidence:answer'];
        $intent = ['work_key' => 'custom.installation', 'work_section' => 'equipment', 'source_fact_ids' => ['fact:answer']];
        $classifier = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
        self::assertSame('equipment:child', $classifier->binding($intent, $facts)['entity_id']);
        foreach (['project:root', 'equipment:other'] as $entityId) {
            try {
                $classifier->binding([...$intent, 'entity_id' => $entityId], $facts);
                self::fail('A selected answer cannot authorize another work target.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_composer_work_source_unrelated', $exception->getMessage());
            }
        }
        foreach ([[], ['equipment:child', 'project:root']] as $related) {
            $facts[0]['related_entity_ids'] = $related;
            try {
                $classifier->binding($intent, $facts);
                self::fail('A raw target without matching current server context is not admissible.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_composer_work_context_unconfirmed', $exception->getMessage());
            }
        }
    }

    public function test_selected_answer_does_not_block_an_independent_source_for_another_work_target(): void
    {
        $facts = $this->sourceClaimInput()->facts;
        $facts[0]['value']['target_entity_id'] = 'equipment:selected';
        $facts[0]['related_entity_ids'] = ['equipment:selected'];
        $facts[0]['evidence_ids'] = ['evidence:answer'];
        $facts[1]['evidence_ids'] = ['evidence:document'];
        $classifier = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
        foreach ([['fact:answer', 'fact:document'], ['fact:document', 'fact:answer']] as $sources) {
            $binding = $classifier->binding(['work_key' => 'equipment.install', 'work_section' => 'equipment',
                'source_fact_ids' => $sources, 'entity_id' => 'equipment:child'], $facts);
            self::assertSame('equipment:child', $binding['entity_id']);
            self::assertSame(['fact:document'], $binding['basis_fact_ids']);
        }
        $facts[0]['related_entity_ids'] = [];
        $binding = $classifier->binding(['work_key' => 'equipment.install', 'work_section' => 'equipment',
            'source_fact_ids' => ['fact:answer', 'fact:document'], 'entity_id' => 'equipment:child'], $facts);
        self::assertSame(['fact:document'], $binding['basis_fact_ids']);
    }

    public function test_input_fingerprint_fences_exact_snapshot_decisions_quantities_candidates_and_missing_documents(): void
    {
        $base = $this->input();

        self::assertNotSame($base->fingerprint(), $this->input(snapshotToken: str_repeat('b', 64))->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->input(decisions: [['id' => 'decision:roof', 'version' => 2]])->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->input(derivedQuantities: [['id' => 'quantity:roof', 'value' => '120.1251', 'unit' => 'm2']])->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->input(missingDocuments: [['code' => 'facade_specification', 'source_fact_ids' => ['fact:facade']]])->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->input(candidates: [[
            'candidate_id' => 'baseline:foundation.changed',
            'work_key' => 'foundation.changed',
            'name' => 'Устройство фундамента',
            'unit' => 'm3',
            'quantity' => '10.0000',
            'quantity_formula' => 'foundation.volume',
            'source_fact_ids' => ['fact:foundation'],
            'technology_package_candidate' => null,
        ]])->fingerprint());
    }

    public function test_candidate_preserves_exact_decimal_quantity_without_accepting_provider_price(): void
    {
        $input = $this->input(candidates: [[
            'candidate_id' => 'baseline:foundation',
            'work_key' => 'foundation',
            'name' => 'Устройство фундамента',
            'unit' => 'm3',
            'quantity' => '10.2500',
            'quantity_formula' => 'foundation.volume',
            'source_fact_ids' => ['fact:foundation'],
            'technology_package_candidate' => null,
        ]]);

        self::assertSame('10.2500', $input->canonicalPayload()['candidates'][0]['quantity']);

        $candidate = $input->candidates[0];
        $candidate['provider_price'] = '999.99';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_composer_candidate_invalid');
        $this->input(candidates: [$candidate]);
    }

    public function test_factory_captures_current_project_model_decision_and_exact_quantity_under_one_snapshot_token(): void
    {
        $models = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('c', 64);
        $entity = new Entity('entity:foundation', 10, 20, 30, $source, 'quantity', 'foundation');
        $fact = new Fact(
            'fact:foundation', 10, 20, 30, $source, $entity->id, 'foundation_volume',
            '10.2500', 'm3', 1.0, 'user_assumption', 'confirmed', [],
        );
        $models->saveSourceModel([$entity], [$fact], []);
        $models->applyDecision(new Decision(
            'decision:foundation', 10, 20, 30, $source, 'fact', $fact->id, $fact->id,
            'user', 'actor:7', 'Подтверждено оператором', 1,
        ), $fact);
        $factory = new EstimateComposerInputFactory($models, 10000);
        $candidate = [
            'candidate_id' => 'baseline:foundation',
            'work_key' => 'foundation',
            'name' => 'Устройство фундамента',
            'unit' => 'm3',
            'quantity' => '10.2500',
            'quantity_formula' => 'foundation.volume',
            'source_fact_ids' => ['fact:foundation'],
            'technology_package_candidate' => null,
        ];

        $input = $factory->capture(10, 20, 30, [$candidate], [[
            'key' => 'quantity:foundation', 'amount' => '10.2500', 'unit' => 'm3',
            'formula_inputs' => ['entity_id' => 'entity:foundation', 'operands' => ['measurement' => ['fact_id' => 'fact:foundation']]],
        ]], []);

        self::assertSame($models->snapshotForPlanning(10, 20, 30, 10001)['token'], $input->snapshotToken);
        self::assertSame('10.2500', $input->facts[0]['value']);
        self::assertSame('decision:foundation', $input->decisions[0]['id']);
        self::assertSame('10.2500', $input->derivedQuantities[0]['value']);
        self::assertSame('quantity:foundation', $input->derivedQuantities[0]['id']);
        self::assertSame('entity:foundation', $input->derivedQuantities[0]['entity_id']);
        self::assertSame(['fact:foundation'], $input->derivedQuantities[0]['operand_fact_ids']);
        self::assertSame([$candidate], $input->candidates);
    }

    public function test_factory_rejects_candidate_source_outside_current_snapshot(): void
    {
        $factory = new EstimateComposerInputFactory(new InMemoryProjectModelRepository, 10000);
        $candidate = [
            'candidate_id' => 'baseline:foundation',
            'work_key' => 'foundation',
            'name' => 'Устройство фундамента',
            'unit' => 'm3',
            'quantity' => '10.2500',
            'quantity_formula' => 'foundation.volume',
            'source_fact_ids' => ['fact:invented'],
            'technology_package_candidate' => null,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_composer_candidate_source_invalid');

        $factory->capture(10, 20, 30, [$candidate], [], []);
    }

    public function test_projector_builds_complete_fixture_without_prices_duplicates_or_zero_guesses(): void
    {
        $projector = new EstimateCompositionProjector;
        $keys = [
            'foundation', 'walls', 'roof', 'facades', 'openings', 'site_preparation',
            'delivery', 'lifting', 'scaffolding', 'waste', 'missing_document_allowance',
        ];
        $items = array_map(static fn (string $key, int $index): array => [
            'key' => $key,
            'name' => $key,
            'item_type' => 'priced_work',
            'unit' => 'm2',
            'quantity' => $index === 10 ? '0.0000' : '10.2500',
            'quantity_formula' => $key.'.quantity',
            'unit_price' => '999.99',
            'total_price' => '999999.99',
            'source_refs' => $key === 'foundation' ? [['fact_id' => 'fact:foundation']] : [],
            'metadata' => $index >= 9 ? ['technology_package_id' => 'package:site-logistics'] : [],
        ], $keys, array_keys($keys));
        $estimates = [[
            'key' => 'house',
            'sections' => [['key' => 'works', 'work_items' => $items]],
        ]];

        $candidates = $projector->candidates($estimates);

        self::assertCount(11, $candidates);
        self::assertCount(11, array_unique(array_column($candidates, 'candidate_id')));
        self::assertSame('10.2500', $candidates[0]['quantity']);
        self::assertNull($candidates[10]['quantity']);
        self::assertArrayNotHasKey('unit_price', $candidates[0]);
        self::assertArrayNotHasKey('total_price', $candidates[0]);
        self::assertSame('package:site-logistics', $candidates[9]['technology_package_candidate']);

        $intents = array_map(static fn (array $candidate): array => [
            'kind' => 'existing',
            'candidate_id' => $candidate['candidate_id'],
            'work_key' => null,
            'name' => null,
            'derived_quantity_id' => null,
            'source_fact_ids' => $candidate['source_fact_ids'],
            'technology_package_candidate' => $candidate['technology_package_candidate'],
            'assumptions' => [],
            'exclusions' => [],
            'missing_document_recommendations' => $candidate['work_key'] === 'missing_document_allowance'
                ? ['Нужна недостающая ведомость объёмов.']
                : [],
        ], $candidates);
        $projected = $projector->apply($estimates, $intents, []);

        self::assertSame('10.2500', $projected[0]['sections'][0]['work_items'][0]['quantity']);
        self::assertSame('999.99', $projected[0]['sections'][0]['work_items'][0]['unit_price']);
        self::assertSame(
            ['Нужна недостающая ведомость объёмов.'],
            $projected[0]['sections'][0]['work_items'][10]['composition_intent']['missing_document_recommendations'],
        );
    }

    public function test_composer_can_add_a_source_grounded_work_when_the_deterministic_baseline_is_empty(): void
    {
        $projector = new EstimateCompositionProjector;
        self::assertSame([], $projector->candidates([]));
        $input = new EstimateComposerInput(
            10,
            20,
            30,
            str_repeat('a', 64),
            [['id' => 'fact:room-area', 'entity_id' => 'entity:room', 'entity_type' => 'room', 'type' => 'area', 'value' => 80.0,
                'unit' => 'm2', 'status' => 'confirmed', 'origin' => 'document', 'evidence_ids' => ['evidence:room-area']]],
            [['id' => 'quantity:room-area', 'entity_id' => 'entity:room', 'value' => '80.000000', 'unit' => 'm2', 'operand_fact_ids' => ['fact:room-area'],
                'formula_key' => 'direct_floor_area', 'source' => 'evidenced']],
            [],
            [],
            [],
            RunEstimateComposer::PROMPT_CONTRACT,
        );

        self::assertSame([], $input->candidates);
        self::assertSame('fact:room-area', $input->facts[0]['id']);

        $result = (new RunEstimateComposer(
            new ComposerRoleRunMemoryRepository,
            new RecordedEstimateComposerModel(['work_intents' => [[
                'kind' => 'supplementary',
                'candidate_id' => null,
                'work_key' => 'finish.floor',
                'name' => 'Устройство цементной стяжки пола',
                'derived_quantity_id' => 'quantity:room-area',
                'source_fact_ids' => ['fact:room-area'],
                'technology_package_candidate' => null,
                'assumptions' => [],
                'exclusions' => [],
                'missing_document_recommendations' => [],
            ]]]),
            'openai/gpt-5-mini',
        ))->run($input);

        self::assertCount(1, $result);
        self::assertSame('supplementary', $result[0]['kind']);
        $projection = new \ReflectionMethod($projector, 'supplementaryItems');
        $items = $projection->invoke(
            $projector,
            [$result[0]['candidate_id'] => $result[0]],
            $input->derivedQuantities,
            $input->facts,
            [$result[0]['candidate_id'] => 'finish.floor'],
        );
        self::assertSame(
            'quantity:room-area',
            $items[0]['metadata']['quantity_key'],
        );
    }

    public function test_text_grounded_supplementary_work_keeps_its_selected_section_and_unit_without_inventing_a_volume(): void
    {
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64),
            [['id' => 'fact:description', 'entity_id' => 'entity:room', 'entity_type' => 'room', 'type' => 'evaluation_answer',
                'value' => ['description' => 'Нужно окрасить стены.'], 'unit' => null, 'status' => 'confirmed',
                'origin' => 'user_input', 'evidence_ids' => ['evidence:description']]],
            [], [], [], [], RunEstimateComposer::PROMPT_CONTRACT, allowedWorkSections: ['finishing']);
        $intent = ['kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'paint-walls', 'name' => 'Окраска стен',
            'derived_quantity_id' => null, 'source_fact_ids' => ['fact:description'], 'technology_package_candidate' => null,
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [], 'unit' => 'm2', 'work_section' => 'finishing'];
        $model = new RecordedEstimateComposerModel(['work_intents' => [$intent, [...$intent, 'work_key' => 'electric-installation', 'work_section' => 'electricity']]]);
        $result = (new RunEstimateComposer(new ComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna'))->run($input);
        self::assertCount(1, $result);
        self::assertSame('finishing', $result[0]['work_section']);
        $facadeApplication = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $previous = ['app' => [\Illuminate\Support\Facades\App::class, \Illuminate\Support\Facades\App::getFacadeRoot()],
            'config' => [\Illuminate\Support\Facades\Config::class, \Illuminate\Support\Facades\Config::getFacadeRoot()],
            'translator' => [\Illuminate\Support\Facades\Lang::class, \Illuminate\Support\Facades\Lang::getFacadeRoot()]];
        $loader = new \Illuminate\Translation\ArrayLoader;
        $loader->addMessages('ru', 'estimate_generation', ['composition' => ['supplementary_title' => 'Дополнительные работы', 'supplementary_section' => 'Работы']]);
        \Illuminate\Support\Facades\App::swap(new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        \Illuminate\Support\Facades\Config::swap(new \Illuminate\Config\Repository(['app' => ['fallback_locale' => 'ru']]));
        \Illuminate\Support\Facades\Lang::swap(new \Illuminate\Translation\Translator($loader, 'ru'));
        try {
            $locals = (new EstimateCompositionProjector)->apply([], $result, [], $input->facts);
        } finally {
            foreach ($previous as $accessor => [$facade, $root]) {
                if ($root !== null) {
                    $facade::swap($root);
                } else {
                    \Illuminate\Support\Facades\Facade::clearResolvedInstance($accessor);
                    $facadeApplication?->forgetInstance($accessor);
                }
            }
        }
        self::assertSame('ai-composer-supplementary:finishing', $locals[0]['key']);
        $item = $locals[0]['sections'][0]['work_items'][0];
        self::assertNull($item['unit']);
        self::assertSame('m2', $item['composition_intent']['unit']);
        self::assertNull($item['quantity']);
        $evaluation = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build(
            ['evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['finishing']], 'local_estimates' => $locals],
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([], [], [], []));
        self::assertCount(1, $evaluation['scenarios'][0]['positions']);
        self::assertSame('finishing', $evaluation['scenarios'][0]['positions'][0]['section']);
        self::assertNull($evaluation['scenarios'][0]['positions'][0]['quantity']);
        self::assertFalse($evaluation['can_confirm_for_apply']);
    }

    public function test_batching_keeps_the_approved_section_whitelist_in_every_physical_part(): void
    {
        $candidates = [];
        for ($index = 0; $index < 65; $index++) {
            $candidates[] = ['candidate_id' => 'candidate:'.$index, 'work_key' => 'work:'.$index, 'name' => 'Отделочные работы', 'unit' => 'm2',
                'quantity' => null, 'quantity_formula' => null, 'source_fact_ids' => ['fact:description'], 'technology_package_candidate' => null];
        }
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), [['id' => 'fact:description', 'value' => 'Отделочные работы']],
            [], [], $candidates, [], RunEstimateComposer::PROMPT_CONTRACT, allowedWorkSections: ['finishing']);
        $parts = (new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerBatchPlanner)->plan($input);
        self::assertCount(3, $parts);
        foreach ($parts as $part) {
            self::assertSame(['finishing'], $part->canonicalPayload()['allowed_work_sections']);
        }
    }

    public function test_multiple_parts_keep_other_entities_and_project_unique_canonical_work_rows(): void
    {
        $items = [];
        for ($index = 0; $index < 65; $index++) {
            $items[] = ['key' => 'baseline:'.$index, 'name' => 'Работа '.$index, 'item_type' => 'priced_work', 'unit' => 'm2',
                'source_refs' => [['fact_id' => 'fact:'.($index % 2 === 0 ? 'a' : 'b')]]];
        }
        $estimates = [['key' => 'roofing', 'sections' => [['key' => 'works', 'work_items' => $items]]]];
        $projector = new EstimateCompositionProjector;
        $facts = array_map(static fn (string $id): array => ['id' => 'fact:'.$id, 'entity_id' => 'roof:'.$id,
            'entity_type' => 'roof', 'type' => 'plan_area', 'value' => '50', 'unit' => 'm2',
            'status' => 'confirmed', 'origin' => 'document', 'evidence_ids' => ['proof:'.$id]], ['a', 'b']);
        $input = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [],
            $projector->candidates($estimates), [], RunEstimateComposer::PROMPT_CONTRACT, allowedWorkSections: ['roofing']);
        $model = new class implements EstimateComposerModel
        {
            public int $calls = 0;

            public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
            {
                $this->calls++;
                $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
                $id = $this->calls === 2 ? 'b' : 'a';
                $records = array_map(static fn (array $candidate): array => ['kind' => 'existing',
                    'candidate_id' => $candidate['candidate_id'], 'work_key' => null, 'name' => null,
                    'derived_quantity_id' => null, 'source_fact_ids' => $candidate['source_fact_ids'],
                    'technology_package_candidate' => null, 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []], $input->candidates);
                $records[] = ['kind' => 'supplementary', 'work_key' => 'roof_underlay', 'name' => 'Подготовка кровли',
                    'entity_id' => 'roof:'.$id, 'derived_quantity_id' => null, 'source_fact_ids' => ['fact:'.$id],
                    'technology_package_candidate' => null, 'unit' => 'm2', 'work_section' => 'roofing',
                    'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];

                return ['work_intents' => $records];
            }
        };
        $composer = new RunEstimateComposer(new MultipartComposerRoleRunMemoryRepository, $model, 'openai/gpt-6-luna');
        $composition = $composer->runDetailed($input, $estimates);
        self::assertSame(3, $model->calls);
        self::assertCount(2, array_filter($composition['intents'], static fn (array $intent): bool => $intent['kind'] === 'supplementary'));
        self::assertSame($composition, $composer->runDetailed($input, $estimates));
        self::assertSame(3, $model->calls);
        $filtered = (new SupplementaryWorkPhysicalGuard)->filter($estimates, $composition, [], $facts);
        self::assertCount(67, $filtered['intents']);
        self::assertCount(1, $filtered['quarantined']);
        $supplementary = [];
        foreach ($filtered['intents'] as $intent) {
            if ($intent['kind'] === 'supplementary') {
                $supplementary[$intent['candidate_id']] = $intent;
            }
        }
        $keys = (new \ReflectionMethod($projector, 'supplementaryPositionKeys'))->invoke($projector, $estimates, $supplementary);
        $additional = (new \ReflectionMethod($projector, 'supplementaryItems'))->invoke($projector, $supplementary, [], $facts, $keys);
        self::assertCount(2, array_unique(array_column($additional, 'key')));
        self::assertSame(['roof_underlay', 'roof_underlay'], array_column($additional, 'work_key'));
        self::assertSame(['roof:a', 'roof:b'], array_column(array_column($additional, 'metadata'), 'scope_entity_id'));
    }

    public function test_timeweb_model_calls_only_the_pinned_model_with_bounded_price_free_json_contract(): void
    {
        $input = $this->input(candidates: [[
            'candidate_id' => 'baseline:foundation',
            'work_key' => 'foundation',
            'name' => 'Устройство фундамента',
            'unit' => 'm3',
            'quantity' => '10.2500',
            'quantity_formula' => 'foundation.volume',
            'source_fact_ids' => ['fact:foundation'],
            'technology_package_candidate' => null,
        ]]);
        $result = $this->validResult($input);
        $wire = new class($result) implements RerankWireClient
        {
            public string $requestedModel = '';

            public array $messages = [];

            public array $options = [];

            public function __construct(private readonly array $result) {}

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->requestedModel = $model;
                $this->messages = $messages;
                $this->options = $options;

                return [
                    'content' => json_encode($this->result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'model' => $model,
                    'usage_available' => true,
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                ];
            }
        };
        $usage = new class implements AiUsageStore
        {
            public array $records = [];

            public function record(AiUsageData $data): void
            {
                $this->records[] = $data;
            }
        };
        $prices = new class implements AiPriceSnapshotResolver
        {
            public function resolve(AiOperationContext $context, string $provider, string $model): AiPriceSnapshot
            {
                return AiPriceSnapshot::fromArray([]);
            }
        };
        $model = new TimewebEstimateComposerModel(
            $wire, $usage, $prices, 'openai/gpt-5-mini', 100000, 4000, 60,
        );
        $attemptId = null;

        $actual = $model->compose($input, static function (string $id) use (&$attemptId): void {
            $attemptId = $id;
        });

        self::assertSame($result, $actual);
        self::assertSame('openai/gpt-5-mini', $wire->requestedModel);
        self::assertSame('json', $wire->options['profile']);
        self::assertArrayNotHasKey('fallback_models', $wire->options);
        self::assertStringContainsString('10.2500', $wire->messages[1]['content']);
        self::assertStringNotContainsString('unit_price', $wire->messages[1]['content']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $attemptId);
        self::assertCount(1, $usage->records);
    }

    #[DataProvider('composerPromptPolicies')]
    public function test_actual_composer_wire_instructions_distinguish_semantic_vocabulary_from_selected_scope(bool $legacy, bool $answered = false): void
    {
        $input = $this->declaredDescriptionInput('Монтаж стендов', $legacy ? [] : ['equipment'], $legacy ? 'legacy' : 'universal');
        if ($answered) {
            $input = new EstimateComposerInput($input->organizationId, $input->projectId, $input->sessionId, $input->snapshotToken,
                $input->facts, $input->derivedQuantities, $input->decisions, $input->candidates, $input->missingDocuments,
                $legacy ? RunEstimateComposer::LEGACY_ANSWER_PROMPT_CONTRACT : RunEstimateComposer::ANSWER_PROMPT_CONTRACT,
                allowedWorkSections: $input->allowedWorkSections);
        }
        $messages = [];
        $wire = $this->createMock(RerankWireClient::class);
        $wire->method('provider')->willReturn('timeweb');
        $wire->expects(self::once())->method('call')->willReturnCallback(function (string $model, array $sent, array $options) use (&$messages, $answered): array {
            $messages = $sent;

            return ['content' => $answered ? '{"work_intents":[],"claim_candidates":[]}' : '{"work_intents":[]}', 'model' => $model, 'usage_available' => true, 'input_tokens' => 100, 'output_tokens' => 10];
        });
        $usage = $this->createMock(AiUsageStore::class);
        $usage->expects(self::once())->method('record');
        $prices = $this->createMock(AiPriceSnapshotResolver::class);
        $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray([]));
        $model = new TimewebEstimateComposerModel($wire, $usage, $prices, 'openai/gpt-6-luna', 100000, 4000, 60);
        $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
        if ($legacy) {
            $old = new EstimateComposerInput($input->organizationId, $input->projectId, $input->sessionId, $input->snapshotToken,
                $input->facts, $input->derivedQuantities, $input->decisions, $input->candidates, $input->missingDocuments,
                'estimate-composer:v4', allowedWorkSections: $input->allowedWorkSections);
            self::assertNotSame($old->fingerprint(), $input->fingerprint());
            $oldModel = new RecordedEstimateComposerModel(['work_intents' => []]);
            $oldOutcome = (new RunEstimateComposer($runs, $oldModel, 'openai/gpt-6-luna'))->runDetailed($old);
            self::assertCount(1, $oldOutcome['quarantined']);
            self::assertSame(1, $oldModel->calls);
        }
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna');
        $outcome = $composer->runDetailed($input);
        self::assertSame($outcome, $composer->runDetailed($input));
        $payload = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
        self::assertSame($input->canonicalPayload(), $payload);
        self::assertStringContainsString('Не вычисляй и не возвращай цены', $messages[0]['content']);
        self::assertStringNotContainsString('related_entity_ids', $messages[0]['content']);
        if ($answered) {
            self::assertStringContainsString('context_kind=composer_answer', $messages[0]['content']);
            self::assertStringContainsString('Это user_assumption, не независимое подтверждение', $messages[0]['content']);
            self::assertStringContainsString('answer_work_sections', $messages[0]['content']);
            self::assertStringContainsString('claim_answer_fact_ids', $messages[0]['content']);
            self::assertStringContainsString('не подтверждённый факт', $messages[0]['content']);
            self::assertStringContainsString('не более 8', $messages[0]['content']);
            self::assertSame([], $payload['claim_answer_fact_ids']);
        } else {
            self::assertStringNotContainsString('context_kind=composer_answer', $messages[0]['content']);
        }
        if ($legacy) {
            if (! $answered) {
                self::assertSame('e63268e9633aec62efbe7ea8959dfea7694241f401670a7b64b63c2da433a95b', hash('sha256', $messages[0]['content']));
            }
            self::assertStringContainsString('semantic_work_sections', $messages[0]['content']);
            self::assertStringContainsString('канонический словарь', $messages[0]['content']);
            self::assertStringContainsString('не выбранный пользователем состав', $messages[0]['content']);
            self::assertStringNotContainsString('оно допускает предварительный состав только в declared_work_sections', $messages[0]['content']);
            self::assertSame($answered ? RunEstimateComposer::LEGACY_ANSWER_PROMPT_CONTRACT : RunEstimateComposer::LEGACY_PROMPT_CONTRACT, $input->contractVersion);
            self::assertArrayNotHasKey('declared_work_sections', $payload['facts'][0]);
        } else {
            if (! $answered) {
                self::assertSame('61ab2e512a78c1ed2eba9d469dd3525119277cedea13bf635ba23f01d89c944a', hash('sha256', $messages[0]['content']));
            }
            self::assertStringContainsString('только в declared_work_sections', $messages[0]['content']);
            self::assertSame($answered ? RunEstimateComposer::ANSWER_PROMPT_CONTRACT : RunEstimateComposer::PROMPT_CONTRACT, $input->contractVersion);
            self::assertSame(['equipment'], $payload['facts'][0]['declared_work_sections']);
        }
        self::assertSame($legacy ? ['estimate-composer:v4', $input->contractVersion, $input->contractVersion]
            : [$input->contractVersion, $input->contractVersion], array_column($runs->inputs, 'promptContractVersion'));
    }

    public static function composerPromptPolicies(): iterable
    {
        yield 'legacy semantic vocabulary' => [true];
        yield 'universal selected scope' => [false];
        yield 'legacy separate answer context' => [true, true];
        yield 'universal separate answer context' => [false, true];
    }

    private function input(
        ?string $snapshotToken = null,
        ?array $decisions = null,
        ?array $derivedQuantities = null,
        ?array $missingDocuments = null,
        ?array $candidates = null,
        array $allowedWorkSections = [],
    ): EstimateComposerInput {
        $workKeys = [
            'foundation', 'walls', 'roof', 'facades', 'openings', 'site_preparation',
            'delivery', 'lifting', 'scaffolding', 'waste', 'missing_document_allowance',
        ];
        $candidates ??= array_map(
            static fn (string $key, int $index): array => [
                'candidate_id' => ($index >= 9 ? 'technology:' : 'baseline:').$key,
                'work_key' => $key,
                'name' => $key,
                'unit' => 'unit',
                'quantity' => '1.0000',
                'quantity_formula' => $key.'.quantity',
                'source_fact_ids' => str_contains($key, 'foundation') ? ['fact:foundation'] : [],
                'technology_package_candidate' => $index >= 9 ? 'package:site-logistics' : null,
            ],
            $workKeys,
            array_keys($workKeys),
        );

        return new EstimateComposerInput(
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            snapshotToken: $snapshotToken ?? str_repeat('a', 64),
            facts: [
                ['id' => 'fact:foundation', 'status' => 'confirmed'],
                ['id' => 'fact:roof', 'entity_id' => 'entity:roof', 'entity_type' => 'roof', 'type' => 'plan_area', 'value' => '120.1250',
                    'unit' => 'm2', 'status' => 'confirmed', 'origin' => 'document', 'evidence_ids' => ['evidence:roof']],
                ['id' => 'fact:facade', 'status' => 'unresolved'],
            ],
            derivedQuantities: $derivedQuantities ?? [['id' => 'quantity:roof', 'entity_id' => 'entity:roof', 'value' => '120.1250', 'unit' => 'm2',
                'operand_fact_ids' => ['fact:roof']]],
            decisions: $decisions ?? [['id' => 'decision:roof', 'version' => 1]],
            candidates: $candidates,
            missingDocuments: $missingDocuments ?? [['code' => 'foundation_detail', 'source_fact_ids' => ['fact:foundation']]],
            contractVersion: RunEstimateComposer::PROMPT_CONTRACT,
            allowedWorkSections: $allowedWorkSections,
        );
    }

    private function validResult(EstimateComposerInput $input): array
    {
        return [
            'work_intents' => array_map(
                static fn (array $candidate): array => [
                    'kind' => 'existing',
                    'candidate_id' => $candidate['candidate_id'],
                    'work_key' => null,
                    'name' => null,
                    'derived_quantity_id' => null,
                    'source_fact_ids' => str_contains($candidate['work_key'], 'foundation') ? ['fact:foundation'] : [],
                    'technology_package_candidate' => $candidate['technology_package_candidate'],
                    'assumptions' => [],
                    'exclusions' => [],
                    'missing_document_recommendations' => $candidate['work_key'] === 'foundation'
                        ? ['Нужен узел фундамента для проверки состава работ.']
                        : [],
                ],
                $input->candidates,
            ),
        ];
    }
}

final class RecordedEstimateComposerModel implements EstimateComposerModel
{
    public int $calls = 0;

    public function __construct(private readonly array $result) {}

    public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
    {
        $this->calls++;
        $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        return $this->result;
    }
}

final class ComposerRoleRunMemoryRepository implements AiRoleRunRepository
{
    public array $inputs = [];

    public array $failures = [];

    public ?AiRoleRunResult $lastResult = null;

    private ?AiRoleRunResult $result = null;

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $this->inputs[] = $input;

        return $this->result === null
            ? new AiRoleRunClaim(1, 'owned', $ownerUuid)
            : new AiRoleRunClaim(1, 'replay', result: $this->result);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId, ?string $creditOperationId = null, ?string $creditRole = null): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->result = $result;
        $this->lastResult = $result;
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void
    {
        $this->failures[] = $failure;
    }

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        return null;
    }

    public function completedFingerprints(int $organizationId, int $projectId, int $sessionId, array $roles, array $sourceVersions): array
    {
        return [];
    }
}

final class MultipartComposerRoleRunMemoryRepository implements AiRoleRunRepository
{
    private array $ids = [];

    private array $results = [];

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $id = $this->ids[$input->subjectVersion] ??= count($this->ids) + 1;

        return isset($this->results[$id]) ? new AiRoleRunClaim($id, 'replay', result: $this->results[$id])
            : new AiRoleRunClaim($id, 'owned', $ownerUuid);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId, ?string $creditOperationId = null, ?string $creditRole = null): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->results[$runId] = $result;
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

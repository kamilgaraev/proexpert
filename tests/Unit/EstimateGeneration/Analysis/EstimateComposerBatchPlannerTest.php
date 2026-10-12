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

    private array $results = [];

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

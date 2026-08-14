<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
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

    #[DataProvider('invalidResultProvider')]
    public function test_composer_rejects_suppression_duplicates_invented_sources_and_provider_prices(
        callable $mutate,
        string $expectedMessage,
    ): void {
        $input = $this->input();
        $result = $mutate($this->validResult($input));
        $composer = new RunEstimateComposer(
            new ComposerRoleRunMemoryRepository,
            new RecordedEstimateComposerModel($result),
            'openai/gpt-5-mini',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $composer->run($input);
    }

    public static function invalidResultProvider(): iterable
    {
        yield 'suppressed candidate' => [
            static function (array $result): array {
                array_pop($result['work_intents']);

                return $result;
            },
            'estimate_composer_candidate_coverage_invalid',
        ];
        yield 'duplicate candidate' => [
            static function (array $result): array {
                $result['work_intents'][1]['candidate_id'] = $result['work_intents'][0]['candidate_id'];

                return $result;
            },
            'estimate_composer_candidate_duplicate',
        ];
        yield 'invented fact' => [
            static function (array $result): array {
                $result['work_intents'][0]['source_fact_ids'] = ['fact:invented'];

                return $result;
            },
            'estimate_composer_source_fact_invalid',
        ];
        yield 'provider price' => [
            static function (array $result): array {
                $result['work_intents'][0]['price'] = '1.00';

                return $result;
            },
            'estimate_work_intent_shape_invalid',
        ];
        yield 'wrong technology package' => [
            static function (array $result): array {
                $result['work_intents'][9]['technology_package_candidate'] = 'package:invented';

                return $result;
            },
            'estimate_composer_technology_candidate_invalid',
        ];
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
            'id' => 'quantity:foundation', 'value' => '10.2500', 'unit' => 'm3',
        ]], []);

        self::assertSame($models->snapshotForPlanning(10, 20, 30, 10001)['token'], $input->snapshotToken);
        self::assertSame('10.2500', $input->facts[0]['value']);
        self::assertSame('decision:foundation', $input->decisions[0]['id']);
        self::assertSame('10.2500', $input->derivedQuantities[0]['value']);
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
            'candidate_id' => $candidate['candidate_id'],
            'source_fact_ids' => $candidate['source_fact_ids'],
            'technology_package_candidate' => $candidate['technology_package_candidate'],
            'assumptions' => [],
            'exclusions' => [],
            'missing_document_recommendations' => $candidate['work_key'] === 'missing_document_allowance'
                ? ['Нужна недостающая ведомость объёмов.']
                : [],
        ], $candidates);
        $projected = $projector->attach($estimates, $intents);

        self::assertSame('10.2500', $projected[0]['sections'][0]['work_items'][0]['quantity']);
        self::assertSame('999.99', $projected[0]['sections'][0]['work_items'][0]['unit_price']);
        self::assertSame(
            ['Нужна недостающая ведомость объёмов.'],
            $projected[0]['sections'][0]['work_items'][10]['composition_intent']['missing_document_recommendations'],
        );
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

    private function input(
        ?string $snapshotToken = null,
        ?array $decisions = null,
        ?array $derivedQuantities = null,
        ?array $missingDocuments = null,
        ?array $candidates = null,
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
                ['id' => 'fact:roof', 'status' => 'confirmed'],
                ['id' => 'fact:facade', 'status' => 'unresolved'],
            ],
            derivedQuantities: $derivedQuantities ?? [['id' => 'quantity:roof', 'value' => '120.1250', 'unit' => 'm2']],
            decisions: $decisions ?? [['id' => 'decision:roof', 'version' => 1]],
            candidates: $candidates,
            missingDocuments: $missingDocuments ?? [['code' => 'foundation_detail', 'source_fact_ids' => ['fact:foundation']]],
            contractVersion: RunEstimateComposer::PROMPT_CONTRACT,
        );
    }

    private function validResult(EstimateComposerInput $input): array
    {
        return [
            'work_intents' => array_map(
                static fn (array $candidate): array => [
                    'candidate_id' => $candidate['candidate_id'],
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

    private ?AiRoleRunResult $result = null;

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $this->inputs[] = $input;

        return $this->result === null
            ? new AiRoleRunClaim(1, 'owned', $ownerUuid)
            : new AiRoleRunClaim(1, 'replay', result: $this->result);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->result = $result;
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

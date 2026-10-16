<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\SupplementaryWorkPhysicalGuard;
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
        self::assertSame('m2', $item['unit']);
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

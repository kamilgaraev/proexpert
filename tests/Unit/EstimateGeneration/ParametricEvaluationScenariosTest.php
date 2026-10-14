<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationScenarioSourceBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ParametricEvaluationScenarios;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class ParametricEvaluationScenariosTest extends TestCase
{
    private const SOURCE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_whole_parameter_cases_use_server_formulas_and_leave_real_facts_unchanged(): void
    {
        [$repository, $capture, $cases, $works, $positions] = $this->fixture();
        $before = serialize($capture['snapshot']);
        $result = (new ParametricEvaluationScenarios(new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory)))
            ->build($cases, $works, $positions, $capture['snapshot'], $capture['token'],
                $repository->currentTechnologyRecommendations(10, 20, 30), $repository->currentCompleteness(10, 20, 30), [], '7');

        self::assertSame(['50', '60', '70'], array_map(static fn (array $definition): string => $definition['quantities'][0]['value'], $result['definitions']));
        self::assertSame('floor_area', $result['recipes']['base']['finish:room']['formula_key']);
        self::assertSame('user_assumption', $result['recipes']['base']['finish:room']['status']);
        self::assertSame($before, serialize($repository->snapshotForPlanning(10, 20, 30, 10001)['snapshot']));
        self::assertSame([], $repository->quantities);
        self::assertSame([], $repository->currentQuantities);
    }

    public function test_stale_parameter_version_is_rejected_before_any_projection_is_saved(): void
    {
        [$repository, $capture, $cases, $works, $positions] = $this->fixture();
        $cases[1]['parameters'][0]['source_fact_version'] = 2;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evaluation_scenario_parameter_stale');
        (new ParametricEvaluationScenarios(new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory)))
            ->build($cases, $works, $positions, $capture['snapshot'], $capture['token'],
                $repository->currentTechnologyRecommendations(10, 20, 30), $repository->currentCompleteness(10, 20, 30), [], '7');
    }

    public function test_dimension_multiplier_converts_square_metres_to_one_hundred_square_metres(): void
    {
        [$repository, $capture, $cases, $works, $positions] = $this->fixture();
        $positions[0]['unit'] = '100 m2';
        $result = (new ParametricEvaluationScenarios(new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory)))
            ->build($cases, $works, $positions, $capture['snapshot'], $capture['token'], null, null, [], '7');
        self::assertSame(['0.5', '0.6', '0.7'], array_map(static fn (array $definition): string => $definition['quantities'][0]['value'], $result['definitions']));
        self::assertSame('0.01', $result['recipes']['base']['finish:room']['unit_conversion_factor']);
        self::assertSame('60', $result['recipes']['base']['finish:room']['source_formula_value']);
    }

    public function test_parameter_values_are_one_compatible_case_not_independently_sorted(): void
    {
        [$repository, $capture, $cases, $works, $positions] = $this->fixture();
        foreach ($cases as $index => &$case) {
            $case['parameters'][] = ['source_fact_id' => 'fact:width', 'source_fact_version' => 1,
                'source_value_hash' => DirectQuantityBinding::valueHash('10'), 'entity_id' => 'room:scenario', 'unit' => 'm',
                'value' => ['10', '9', '8'][$index]];
        }
        unset($case);
        $service = new ParametricEvaluationScenarios(new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory));
        $result = $service->build($cases, $works, $positions, $capture['snapshot'], $capture['token'],
            $repository->currentTechnologyRecommendations(10, 20, 30), $repository->currentCompleteness(10, 20, 30), [], '7');
        self::assertSame(['50', '54', '56'], array_map(static fn (array $definition): string => $definition['quantities'][0]['value'], $result['definitions']));
        $cases[2]['parameters'][1]['value'] = '1';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evaluation_scenario_quantity_order_invalid');
        $service->build($cases, $works, $positions, $capture['snapshot'], $capture['token'],
            $repository->currentTechnologyRecommendations(10, 20, 30), $repository->currentCompleteness(10, 20, 30), [], '7');
    }

    public function test_another_entity_cannot_supply_the_quantity(): void
    {
        [$repository, $capture, $cases, $works, $positions] = $this->fixture();
        $works['finish:room']['metadata']['scope_entity_id'] = 'room:another';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evaluation_scenario_parameters_not_calculable');
        (new ParametricEvaluationScenarios(new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory)))
            ->build($cases, $works, $positions, $capture['snapshot'], $capture['token'],
                $repository->currentTechnologyRecommendations(10, 20, 30), $repository->currentCompleteness(10, 20, 30), [], '7');
    }

    public function test_basic_formula_from_explicit_inputs_does_not_require_a_paid_technology_pass(): void
    {
        [$repository, $capture, $cases, $works, $positions] = $this->fixture();
        $result = (new ParametricEvaluationScenarios(new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory)))
            ->build($cases, $works, $positions, $capture['snapshot'], $capture['token'], null, null, [], '7');
        self::assertSame(['50', '60', '70'], array_map(static fn (array $definition): string => $definition['quantities'][0]['value'], $result['definitions']));
        self::assertSame([], $repository->quantities);
    }

    public function test_changed_operand_invalidates_projection_but_choice_and_materialization_do_not(): void
    {
        [, $capture] = $this->fixture();
        $snapshot = $capture['snapshot'];
        $binding = new EvaluationScenarioSourceBinding;
        $selection = ['parameter_projection' => ['version' => ParametricEvaluationScenarios::VERSION,
            'source_fingerprint' => $binding->fingerprint($snapshot, [])]];
        $anchor = new Entity('entity:choice', 10, 20, 30, self::SOURCE, 'quantity', 'entity:choice', ['identity' => ['evaluation_anchor' => 'scenario-set']]);
        $ledger = new Entity('entity:ledger', 10, 20, 30, self::SOURCE, 'quantity', 'entity:ledger', ['identity' => ['work_item_key' => 'finish:room']]);
        $choice = new Fact('fact:choice', 10, 20, 30, self::SOURCE, $anchor->id, 'evaluation_scenario', [], null, 0.0, 'user_assumption', 'confirmed', []);
        $accepted = new Fact('fact:accepted', 10, 20, 30, self::SOURCE, $ledger->id, 'quantity', '60', 'm2', 0.0, 'user_assumption', 'confirmed', []);
        $targetQuantity = new Fact('fact:target-quantity', 10, 20, 30, self::SOURCE, 'room:scenario', 'work_quantity_'.str_repeat('a', 48),
            '60', 'm2', 0.0, 'user_assumption', 'confirmed', []);
        $expanded = new ProjectModelSnapshot([...$snapshot->entities, $anchor, $ledger], [...$snapshot->facts, $choice, $accepted, $targetQuantity], $snapshot->evidence, []);
        self::assertTrue($binding->current($selection, $expanded, []));
        $changed = array_map(static fn (Fact $fact): Fact => $fact->id === 'fact:width'
            ? new Fact($fact->id, 10, 20, 30, self::SOURCE, $fact->entityId, $fact->type, '11', $fact->unit, 0.0,
                'document', 'confirmed', $fact->evidenceIds, 2) : $fact, $expanded->facts);
        self::assertFalse($binding->current($selection, new ProjectModelSnapshot($expanded->entities, $changed, $expanded->evidence, []), []));
        self::assertTrue($binding->current(['definitions' => []], $snapshot, []));
    }

    private function fixture(): array
    {
        $repository = new InMemoryProjectModelRepository;
        $room = new Entity('room:scenario', 10, 20, 30, self::SOURCE, 'room', 'room:scenario');
        $facts = [];
        $evidence = [];
        foreach (['length' => '5', 'width' => '10'] as $parameter => $value) {
            $proof = new Evidence('evidence:'.$parameter, 10, 20, 30, self::SOURCE, 'artifact:plan', 'document',
                1, null, 'cad:room:'.$parameter, 'native_numeric_parser', fingerprint: hash('sha256', $parameter));
            $evidence[] = $proof;
            $facts[] = new Fact('fact:'.$parameter, 10, 20, 30, self::SOURCE, $room->id, $parameter, $value, 'm',
                0.0, 'document', 'confirmed', [$proof->id], nativeNumericProofs: [$proof->id => [
                    'entity_id' => $room->id, 'source_version' => self::SOURCE, 'parameter' => $parameter,
                    'measurement' => FactVocabulary::measurementSignature($value, 'm'), 'evidence_fingerprint' => $proof->fingerprint,
                ]]);
        }
        $repository->saveSourceModel([$room], $facts, $evidence);
        $token = $repository->snapshotForPlanning(10, 20, 30, 10001)['token'];
        $repository->replaceTechnologyRecommendations(10, 20, 30, self::SOURCE, $token, 'technology:v1', str_repeat('c', 64), [], []);
        $repository->replaceCompleteness(10, 20, 30, self::SOURCE, $token, 'technology:v1', str_repeat('c', 64), 'rules:v1', str_repeat('d', 64), [], []);
        $cases = [];
        foreach (['minimum' => '5', 'base' => '6', 'maximum' => '7'] as $id => $value) {
            $cases[] = ['id' => $id, 'basis' => 'Введённая пользователем длина '.$value.' м', 'parameters' => [[
                'source_fact_id' => 'fact:length', 'source_fact_version' => 1, 'source_value_hash' => DirectQuantityBinding::valueHash('5'),
                'entity_id' => $room->id, 'unit' => 'm', 'value' => $value,
            ]]];
        }
        $works = ['finish:room' => ['key' => 'finish:room', 'work_key' => 'finish.floor', 'unit' => 'm2', 'metadata' => ['scope_entity_id' => $room->id]]];

        return [$repository, $repository->snapshotForPlanning(10, 20, 30, 10001), $cases, $works, [['key' => 'finish:room', 'unit' => 'm2']]];
    }
}

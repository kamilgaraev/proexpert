<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScenarioEstimateCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EvaluationScenarioSetTest extends TestCase
{
    public function test_three_proven_cases_calculate_a_range_and_never_certify_assumed_sizes(): void
    {
        [$snapshot, $decisions, $selection] = $this->proof();
        $positions = $this->positions();
        $scenarios = (new EvaluationScenarioSet)->project($positions, [], $snapshot, $decisions, $selection);
        $result = (new ScenarioEstimateCalculator)->calculate($scenarios, ['finishing'], []);
        self::assertSame(['RUB' => ['minimum' => '1200.00', 'maximum' => '3000.00']], $result['scenario_range']);
        self::assertSame('scenario_estimate', $result['result_class']);
        self::assertSame('assumed', $scenarios[0]['positions'][0]['quantity_status']);
        self::assertSame([], $scenarios[0]['positions'][0]['quantity_evidence_details']);
        self::assertSame('scenario_assumption', $scenarios[0]['positions'][0]['quantity_basis']['kind']);
        self::assertFalse($result['accuracy_calibrated']);
        self::assertNull($positions[0]['quantity']);
    }

    public function test_unproven_or_changed_user_choice_cannot_create_a_scenario_range(): void
    {
        [$snapshot, $decisions, $selection] = $this->proof();
        $selection['definitions'][0]['basis'] = 'Изменённое основание';
        $this->expectExceptionMessage('evaluation_scenario_selection_not_proven');
        (new EvaluationScenarioSet)->project($this->positions(), [], $snapshot, $decisions, $selection);
    }

    public function test_cases_with_different_work_keys_are_rejected(): void
    {
        $definitions = $this->definitions();
        $definitions[1]['quantities'][0]['work_item_key'] = 'other';
        $this->expectException(InvalidArgumentException::class);
        (new EvaluationScenarioSet)->validate($definitions, [...$this->positions(), ['key' => 'other', 'unit' => 'm2']]);
    }

    public function test_inverted_case_quantities_and_wrong_units_are_rejected(): void
    {
        foreach (['value' => '31', 'unit' => 'm3'] as $field => $value) {
            $definitions = $this->definitions();
            $definitions[0]['quantities'][0][$field] = $value;
            try {
                (new EvaluationScenarioSet)->validate($definitions, $this->positions());
                self::fail('Invalid scenario was accepted');
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('evaluation_scenario_quantity_', $exception->getMessage());
            }
        }
    }

    public function test_a_normative_sum_for_another_volume_is_not_scaled_as_a_unit_price(): void
    {
        [$snapshot, $decisions, $selection] = $this->proof();
        $positions = $this->positions();
        $positions[0]['price_snapshot']['source_type'] = 'normative';
        $positions[0]['price_snapshot']['calculation_basis'] = ['kind' => 'normative_resource_sum', 'quantity' => '20'];
        $scenarios = (new EvaluationScenarioSet)->project($positions, [], $snapshot, $decisions, $selection);
        self::assertNull($scenarios[0]['positions'][0]['price_snapshot']);
        self::assertNotNull($scenarios[1]['positions'][0]['price_snapshot']);
        self::assertNull($scenarios[2]['positions'][0]['price_snapshot']);
        self::assertContains('scenario_normative_resources_require_recalculation', $scenarios[0]['positions'][0]['warnings']);
    }

    public function test_review_projection_does_not_replace_actual_work_quantity_with_a_hypothetical_case(): void
    {
        [$snapshot, $decisions, $selection] = $this->proof();
        $scenarios = (new EvaluationScenarioSet)->project($this->positions(), [], $snapshot, $decisions, $selection);
        $draft = ['evaluation_result' => ['scenarios' => $scenarios],
            'local_estimates' => [['key' => 'rough_finishing', 'sections' => [['work_items' => [['key' => 'floor', 'name' => 'Пол', 'quantity' => null, 'unit' => 'm2']]]]]]];
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        self::assertNull($draft['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
    }

    public function test_a_supplier_offer_valid_for_base_quantity_cannot_price_other_quantities(): void
    {
        [$snapshot, $decisions, $selection] = $this->proof();
        $positions = $this->positions();
        $positions[0]['price_snapshot'] = [...$positions[0]['price_snapshot'], 'source_type' => 'supplier', 'price_quantity' => '20'];
        $scenarios = (new EvaluationScenarioSet)->project($positions, [], $snapshot, $decisions, $selection);
        self::assertNull($scenarios[0]['positions'][0]['price_snapshot']);
        self::assertNotNull($scenarios[1]['positions'][0]['price_snapshot']);
        self::assertNull($scenarios[2]['positions'][0]['price_snapshot']);
        self::assertContains('scenario_supplier_quantity_requires_offer', $scenarios[2]['positions'][0]['warnings']);
    }

    public function test_jsonb_object_key_order_does_not_invalidate_the_same_proven_scenario_choice(): void
    {
        [$snapshot, $decisions, $selection] = $this->proof();
        $fact = $snapshot->facts[0];
        $value = $fact->value;
        $value['definitions'] = array_map(static function (array $definition): array {
            $definition['quantities'] = array_map(static fn (array $quantity): array => array_reverse($quantity, true), $definition['quantities']);

            return array_reverse($definition, true);
        }, $value['definitions']);
        $reordered = new Fact($fact->id, $fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion, $fact->entityId,
            $fact->type, $value, $fact->unit, $fact->confidence, $fact->origin, $fact->status, $fact->evidenceIds);
        $snapshot = new ProjectModelSnapshot([], [$reordered], $snapshot->evidence, []);
        self::assertTrue((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof)->accepted($snapshot, $reordered, $decisions[0]));
        self::assertCount(3, (new EvaluationScenarioSet)->project($this->positions(), [], $snapshot, $decisions, $selection));
    }

    public function test_scenario_volumes_keep_the_selected_scaled_work_unit(): void
    {
        $positions = $this->positions();
        $positions[0]['unit'] = '100 м2';
        $definitions = $this->definitions();
        foreach ($definitions as &$definition) {
            $definition['quantities'][0]['unit'] = '100 м2';
        }
        unset($definition);
        $validated = (new EvaluationScenarioSet)->validate($definitions, $positions);
        self::assertSame('100 м2', $validated[0]['quantities'][0]['unit']);
        self::assertSame('12', $validated[0]['quantities'][0]['value']);
    }

    private function definitions(): array
    {
        return array_map(static fn (string $id, string $quantity): array => ['id' => $id,
            'basis' => 'Площадь помещения по выбранному пользователем варианту',
            'quantities' => [['work_item_key' => 'floor', 'value' => $quantity, 'unit' => 'm2']]],
            ['minimum', 'base', 'maximum'], ['12', '20', '30']);
    }

    private function positions(): array
    {
        return [['key' => 'floor', 'section' => 'finishing', 'quantity' => null, 'unit' => 'm2', 'warnings' => [],
            'price_snapshot' => ['unit_price' => '100', 'unit' => 'm2', 'currency' => 'RUB', 'verified' => true,
                'source_type' => 'catalog', 'source_reference' => 'catalog:1', 'as_of_date' => '2026-10-10']]];
    }

    private function proof(): array
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $value = ['version' => EvaluationScenarioSet::VERSION, 'definitions' => $this->definitions(),
            'decision_id' => 'decision:1', 'reason' => 'Сценарные допущения пользователя'];
        $evidence = new Evidence('evidence:1', 1, 2, 3, $version, 'input:3', 'user_input', null, null,
            'input_payload.evaluation_choices', 'user_input_normalizer', 'input:3', hash('sha256', CanonicalPipelineJson::encode($value)));
        $fact = new Fact('fact:1', 1, 2, 3, $version, 'entity:1', 'evaluation_scenario', $value, null, 0.0,
            'user_input', 'confirmed', [$evidence->id]);
        $decision = new Decision('decision:1', 1, 2, 3, $version, 'fact', $fact->id, $fact->id, 'user', '4', $value['reason'], 1, [$evidence->id]);

        return [new ProjectModelSnapshot([], [$fact], [$evidence], []), [$decision],
            ['version' => EvaluationScenarioSet::VERSION, 'definitions' => $this->definitions(), 'fact_id' => $fact->id, 'decision_id' => $decision->id]];
    }
}

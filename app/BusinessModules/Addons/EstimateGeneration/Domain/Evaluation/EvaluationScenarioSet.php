<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Compatible user-defined cases; their numbers remain assumptions, never measured facts. */
final class EvaluationScenarioSet
{
    public const VERSION = 'evaluation-scenarios:v1';

    public function validate(array $definitions, array $positions): array
    {
        if (! array_is_list($definitions) || count($definitions) !== 3) {
            throw new InvalidArgumentException('evaluation_scenario_set_invalid');
        }
        $works = array_column($positions, null, 'key');
        if (count($works) !== count($positions)) {
            throw new InvalidArgumentException('evaluation_scenario_work_ambiguous');
        }
        $result = [];
        $expectedKeys = null;
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                throw new InvalidArgumentException('evaluation_scenario_definition_invalid');
            }
            $id = $definition['id'] ?? null;
            $quantities = $definition['quantities'] ?? null;
            if (! in_array($id, ['minimum', 'base', 'maximum'], true) || isset($result[$id])
                || ! is_string($definition['basis'] ?? null) || trim($definition['basis']) === '' || strlen($definition['basis']) > 1000
                || ! is_array($quantities) || ! array_is_list($quantities) || $quantities === [] || count($quantities) > 10000) {
                throw new InvalidArgumentException('evaluation_scenario_definition_invalid');
            }
            $values = [];
            foreach ($quantities as $quantity) {
                if (! is_array($quantity)) {
                    throw new InvalidArgumentException('evaluation_scenario_quantity_invalid');
                }
                $key = $quantity['work_item_key'] ?? null;
                $value = $quantity['value'] ?? null;
                if (! is_string($key) || ! isset($works[$key]) || isset($values[$key])
                    || ($quantity['unit'] ?? null) !== ($works[$key]['unit'] ?? null)
                    || ! is_string($quantity['unit'] ?? null) || WorkQuantityInputUnit::parse($quantity['unit']) === null
                    || ! is_string($value) || preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,4})?\z/', $value) !== 1
                    || ! OrdinaryEstimateDecimal::fitsQuantity($value) || ! BigDecimal::of($value)->isGreaterThan(0)) {
                    throw new InvalidArgumentException('evaluation_scenario_quantity_invalid');
                }
                $values[$key] = ['work_item_key' => $key, 'value' => (string) BigDecimal::of($value)->strippedOfTrailingZeros(), 'unit' => $quantity['unit']];
            }
            ksort($values, SORT_STRING);
            $keys = array_keys($values);
            if ($expectedKeys !== null && $keys !== $expectedKeys) {
                throw new InvalidArgumentException('evaluation_scenario_scope_incompatible');
            }
            $expectedKeys = $keys;
            $result[$id] = ['id' => $id, 'basis' => trim($definition['basis']), 'quantities' => array_values($values)];
        }
        foreach ($expectedKeys as $key) {
            $values = [];
            foreach (['minimum', 'base', 'maximum'] as $id) {
                $values[$id] = array_column($result[$id]['quantities'], 'value', 'work_item_key')[$key];
            }
            if (BigDecimal::of($values['minimum'])->isGreaterThan($values['base']) || BigDecimal::of($values['base'])->isGreaterThan($values['maximum'])) {
                throw new InvalidArgumentException('evaluation_scenario_quantity_order_invalid');
            }
        }

        return array_map(static fn (string $id): array => $result[$id], ['minimum', 'base', 'maximum']);
    }

    public function project(array $positions, array $assumptions, ProjectModelSnapshot $snapshot, array $decisions, mixed $selection): array
    {
        if ($selection === null) {
            return [['id' => 'base', 'positions' => $positions, 'assumptions' => $assumptions]];
        }
        if (! is_array($selection) || ($selection['version'] ?? null) !== self::VERSION || ! is_array($selection['definitions'] ?? null)) {
            throw new InvalidArgumentException('evaluation_scenario_selection_invalid');
        }
        $definitions = $this->validate($selection['definitions'], $positions);
        $expected = ['version' => self::VERSION, 'definitions' => $definitions];
        if (! (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $selection, 'evaluation_scenario', $expected)) {
            throw new InvalidArgumentException('evaluation_scenario_selection_not_proven');
        }
        $scenarios = [];
        foreach ($definitions as $definition) {
            $overrides = array_column($definition['quantities'], null, 'work_item_key');
            $scenarioPositions = [];
            // A literal scenario volume replaces the earlier quantity recipe, including its operand assumptions.
            // Retain unrelated/global assumptions and record the accepted scenario basis once below.
            $scenarioAssumptions = array_values(array_filter($assumptions, static fn (mixed $assumption): bool => ! is_array($assumption) || ! isset($assumption['position_key']) || ! isset($overrides[$assumption['position_key']])));
            foreach ($positions as $position) {
                $override = $overrides[$position['key']] ?? null;
                if ($override !== null) {
                    $position['quantity'] = $override['value'];
                    $position['quantity_status'] = 'assumed';
                    $position['quantity_formula'] = null;
                    $position['quantity_basis'] = ['kind' => 'scenario_assumption', 'scenario_id' => $definition['id'],
                        'fact_id' => $selection['fact_id'], 'decision_id' => $selection['decision_id'], 'reason' => $definition['basis']];
                    // Actual-size evidence remains on the source work; it cannot certify a hypothetical size.
                    $position['quantity_evidence_details'] = [];
                    $price = $position['price_snapshot'];
                    if (isset($price['calculation_basis']) && ! BigDecimal::of($override['value'])->isEqualTo($price['calculation_basis']['quantity'] ?? '-1')) {
                        $position['price_snapshot'] = null;
                        $position['warnings'][] = 'scenario_normative_resources_require_recalculation';
                    }
                    if (($price['source_type'] ?? null) === 'supplier'
                        && (! is_string($price['price_quantity'] ?? null) || ! BigDecimal::of($override['value'])->isEqualTo($price['price_quantity']))) {
                        $position['price_snapshot'] = null;
                        $position['warnings'][] = 'scenario_supplier_quantity_requires_offer';
                    }
                    $scenarioAssumptions[] = ['position_key' => $position['key'], 'basis' => $definition['basis'],
                        'quantity' => $override['value'], 'unit' => $override['unit'], 'status' => 'user_assumption'];
                }
                $scenarioPositions[] = $position;
            }
            $scenarios[] = ['id' => $definition['id'], 'positions' => $scenarioPositions, 'assumptions' => $scenarioAssumptions];
        }

        return $scenarios;
    }
}

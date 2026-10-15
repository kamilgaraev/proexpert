<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** Selects one compatible case; accepting it never turns its assumptions into measured dimensions. */
final readonly class SelectedEvaluationScenario
{
    public function __construct(private ScenarioEstimateCalculator $calculator = new ScenarioEstimateCalculator) {}

    public function select(array $result, string $scenarioId): array
    {
        (new EvaluationResultValidator($this->calculator))->validate($result);
        if (! in_array($scenarioId, ['minimum', 'base', 'maximum'], true) || ($result['scope_confirmed'] ?? false) !== true
            || ($result['missing_requirements'] ?? []) !== []) {
            throw new InvalidArgumentException('selected_scenario_scope_incomplete');
        }
        $matches = array_values(array_filter($result['scenarios'], static fn (array $scenario): bool => $scenario['id'] === $scenarioId));
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('selected_scenario_missing');
        }
        $selected = $this->calculator->calculate($matches, $result['selected_scope'], [], true);
        if (! $selected['can_confirm_for_apply']) {
            throw new InvalidArgumentException('selected_scenario_quantities_prices_or_currency_unresolved');
        }
        $scenario = $selected['scenarios'][0];
        $semanticScenario = $scenario;
        foreach ($semanticScenario['positions'] as &$position) {
            unset($position['proposed_entity_context']);
        }
        unset($position);

        return ['scenario_id' => $scenarioId, 'selected_scope' => $result['selected_scope'],
            'profile_version' => $result['profile_version'], 'scenario' => $scenario,
            'scenario_hash' => hash('sha256', CanonicalPipelineJson::encode($semanticScenario)),
            'total_by_currency' => $scenario['total_by_currency'],
            'assumptions' => $scenario['assumptions'], 'quantity_status_preserved' => true];
    }
}

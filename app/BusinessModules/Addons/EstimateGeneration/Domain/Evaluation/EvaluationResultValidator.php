<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

final readonly class EvaluationResultValidator
{
    public function __construct(private ScenarioEstimateCalculator $calculator = new ScenarioEstimateCalculator) {}

    public function validate(array $result): void
    {
        if (! is_array($result['scenarios'] ?? null) || ! is_array($result['selected_scope'] ?? null)
            || ! is_array($result['missing_requirements'] ?? null) || ! is_bool($result['scope_confirmed'] ?? null)
            || ! is_string($result['profile_version'] ?? null) || $result['profile_version'] === '' || strlen($result['profile_version']) > 64) {
            throw new InvalidArgumentException('evaluation_result_contract_invalid');
        }
        foreach ($result['scenarios'] as $scenario) {
            if (! is_array($scenario) || ! is_array($scenario['positions'] ?? null) || $scenario['positions'] === []) {
                throw new InvalidArgumentException('evaluation_result_has_no_work_scope');
            }
        }
        $recomputed = $this->calculator->calculate($result['scenarios'], $result['selected_scope'], $result['missing_requirements'], $result['scope_confirmed']);
        foreach ($recomputed as $key => $value) {
            if (! array_key_exists($key, $result) || CanonicalPipelineJson::encode([$value]) !== CanonicalPipelineJson::encode([$result[$key]])) {
                throw new InvalidArgumentException('evaluation_result_not_reconciled');
            }
        }
    }
}

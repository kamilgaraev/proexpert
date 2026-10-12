<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

/** Currency choices are applied after scenario volumes, so every scenario rounds its own source line. */
final class EvaluationScenarioCurrencyProjector
{
    public function project(array $scenarios, array $selections, ProjectModelSnapshot $snapshot, array $decisions): array
    {
        foreach ($scenarios as &$scenario) {
            foreach ($scenario['positions'] as &$position) {
                $price = $position['price_snapshot'] ?? null;
                if (! is_array($price)) {
                    continue;
                }
                if (isset($price['currency_conversion'])) {
                    if (! is_array($price['currency_conversion'])) {
                        throw new \InvalidArgumentException('evaluation_currency_basis_invalid');
                    }
                    (new EvaluationCurrencyConversion)->sourceMoney($price['currency_conversion']);
                    $price = $price['currency_conversion']['source_snapshot'];
                }
                $currency = $price['currency'] ?? null;
                $selection = is_string($currency) ? ($selections[$currency] ?? null) : null;
                if ($currency !== 'RUB' && is_array($selection) && is_string($position['quantity'] ?? null)) {
                    try {
                        $terms = (new EvaluationCurrencyConversion)->terms($selection);
                        if ($terms['from_currency'] === $currency && is_string($selection['decision_id'] ?? null)
                            && (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $selection, 'evaluation_currency',
                                [...$terms, 'decision_id' => $selection['decision_id']])) {
                            $price = (new EvaluationCurrencyConversion)->snapshot($price, $position['quantity'], $selection);
                        }
                    } catch (\InvalidArgumentException) {
                        // Invalid/unconfirmed rates preserve the original currency and its explicit blocker.
                    }
                }
                $position['price_snapshot'] = $price;
            }
            unset($position);
        }
        unset($scenario);

        return $scenarios;
    }
}

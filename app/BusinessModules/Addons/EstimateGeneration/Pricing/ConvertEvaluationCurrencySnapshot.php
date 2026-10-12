<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationCurrencyConversion;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationSelectionVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class ConvertEvaluationCurrencySnapshot
{
    public function convert(array $price, array $item, EstimateGenerationSession $session,
        ?ProjectModelSnapshot $snapshot, ?array $decisions): array
    {
        $currency = $price['currency'] ?? null;
        $selection = is_string($currency) ? ($session->input_payload['currency_selections'][$currency] ?? null) : null;
        if ($currency === 'RUB' || $snapshot === null || $decisions === null || ! is_array($selection)
            || ! is_string($item['quantity'] ?? null)) {
            return $price;
        }
        $scope = $snapshot->facts[0] ?? $snapshot->entities[0] ?? $snapshot->evidence[0] ?? null;
        if ($scope === null || [$scope->organizationId, $scope->projectId, $scope->sessionId]
            !== [(int) $session->organization_id, (int) $session->project_id, (int) $session->id]) {
            throw new \DomainException('evaluation_currency_scope_invalid');
        }
        try {
            $terms = (new EvaluationCurrencyConversion)->terms($selection);
            if ($terms['from_currency'] !== $currency || ! is_string($selection['decision_id'] ?? null)
                || ! (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $selection, 'evaluation_currency', [...$terms, 'decision_id' => $selection['decision_id']])) {
                return $price;
            }

            return (new EvaluationCurrencyConversion)->snapshot($price, $item['quantity'], $selection);
        } catch (\InvalidArgumentException) {
            return $price;
        }
    }
}

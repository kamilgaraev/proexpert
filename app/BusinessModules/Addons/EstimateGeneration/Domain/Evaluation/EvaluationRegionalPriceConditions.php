<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EvaluationRegionalPriceConditions
{
    public function normalize(array $conditions, array $regionalContext): array
    {
        if (array_diff(array_keys($conditions), ['as_of_date', 'vat_mode', 'vat_rate', 'delivery', 'basis']) !== []
            || ! is_string($conditions['as_of_date'] ?? null) || ! is_string($conditions['basis'] ?? null)
            || mb_strlen(trim($conditions['basis'])) < 10 || mb_strlen($conditions['basis']) > 1000
            || ! in_array($conditions['vat_mode'] ?? null, ['excluded', 'not_applicable'], true)
            || ! in_array($conditions['delivery'] ?? null, ['included', 'outside_scope'], true)) {
            throw new InvalidArgumentException('regional_price_conditions_invalid');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $conditions['as_of_date']);
        if ($date === false || $date->format('Y-m-d') !== $conditions['as_of_date']
            || (new \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver)
                ->applicabilityDate($regionalContext) !== $conditions['as_of_date']) {
            throw new InvalidArgumentException('regional_price_conditions_invalid');
        }
        $tax = (new EvaluationVatProjection)->amount('1.00', $conditions);

        return ['as_of_date' => $conditions['as_of_date'], 'vat_mode' => $tax['vat_mode'], 'vat_rate' => $tax['vat_rate'],
            'delivery' => $conditions['delivery'], 'basis' => trim($conditions['basis'])];
    }

    public function accepted(array $draft, \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot $snapshot, array $decisions): ?array
    {
        $selection = $draft['evaluation_policy']['regional_price_selection'] ?? null;
        if (! is_array($selection) || ! is_array($selection['conditions'] ?? null)) {
            return null;
        }
        try {
            $conditions = $this->normalize($selection['conditions'], $draft['regional_context']);
        } catch (InvalidArgumentException) {
            return null;
        }
        $version = $draft['regional_context']['estimate_regional_price_version_id'] ?? null;
        $expected = ['estimate_regional_price_version_id' => $version, 'regional_context' => $draft['regional_context'], 'conditions' => $conditions];
        if (! is_int($version) || ! (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $selection, 'evaluation_price', $expected)) {
            return null;
        }

        return [...$conditions, 'estimate_regional_price_version_id' => $version, 'decision_id' => $selection['decision_id']];
    }
}

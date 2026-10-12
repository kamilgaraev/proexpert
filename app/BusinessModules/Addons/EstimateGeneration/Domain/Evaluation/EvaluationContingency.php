<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use InvalidArgumentException;

/** A fixed, explicitly accepted monetary component with its own source tax terms. */
final class EvaluationContingency
{
    public const CONTRACT = 'evaluation-contingency:v1';

    public function terms(array $input): array
    {
        $amount = $input['quoted_amount'] ?? null;
        $basis = $input['basis'] ?? null;
        if (($input['currency'] ?? null) !== 'RUB' || ($input['kind'] ?? null) !== 'fixed'
            || ! is_string($amount) || preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/', $amount) !== 1
            || ! is_string($basis) || trim($basis) === '' || mb_strlen($basis) > 1000
            || preg_match('/[\x00-\x1F\x7F]/', $basis) === 1) {
            throw new InvalidArgumentException('evaluation_contingency_terms_invalid');
        }
        $amount = OrdinaryEstimateDecimal::money($amount);
        $money = (new EvaluationVatProjection)->amount($amount, $input);
        OrdinaryEstimateDecimal::money($money['gross_amount']);

        return ['kind' => 'fixed', 'currency' => 'RUB', 'quoted_amount' => $amount, 'vat_mode' => $money['vat_mode'],
            'vat_rate' => $money['vat_rate'], 'basis' => trim($basis)];
    }

    public function component(array $selection): array
    {
        $terms = $this->terms($selection);
        foreach (['fact_id' => '/\Afact:[a-f0-9]{64}\z/', 'decision_id' => '/\Adecision:[a-f0-9]{64}\z/'] as $key => $pattern) {
            if (! is_string($selection[$key] ?? null) || preg_match($pattern, $selection[$key]) !== 1) {
                throw new InvalidArgumentException('evaluation_contingency_confirmation_missing');
            }
        }

        return ['contract' => self::CONTRACT, ...$terms, 'fact_id' => $selection['fact_id'], 'decision_id' => $selection['decision_id'],
            'money' => (new EvaluationVatProjection)->amount($terms['quoted_amount'], $terms)];
    }
}

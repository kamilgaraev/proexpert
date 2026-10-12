<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Tax terms come from the accepted source; no tax rate is inferred by the model. */
final class EvaluationVatProjection
{
    public function amount(string $quotedAmount, array $price): array
    {
        $amount = BigDecimal::of($quotedAmount)->toScale(2, RoundingMode::Unnecessary);
        $mode = $price['vat_mode'] ?? null;
        if ($amount->isLessThan(0) || ! in_array($mode, ['included', 'excluded', 'not_applicable'], true)) {
            throw new InvalidArgumentException('evaluation_vat_terms_unknown');
        }
        if ($mode === 'not_applicable') {
            return ['net_amount' => (string) $amount, 'vat_amount' => '0.00', 'gross_amount' => (string) $amount,
                'vat_mode' => $mode, 'vat_rate' => '0', 'quoted_amount' => (string) $amount];
        }
        $rawRate = $price['vat_rate'] ?? null;
        if ((! is_string($rawRate) && ! is_int($rawRate)) || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/', (string) $rawRate) !== 1) {
            throw new InvalidArgumentException('evaluation_vat_rate_unknown');
        }
        $rate = BigDecimal::of((string) $rawRate);
        if ($rate->isGreaterThan(100)) {
            throw new InvalidArgumentException('evaluation_vat_rate_invalid');
        }
        $factor = BigDecimal::one()->plus($rate->withPointMovedLeft(2));
        if ($mode === 'included') {
            $gross = $amount;
            $net = BigDecimal::of(OrdinaryEstimateDecimal::money($gross->dividedBy($factor, 18, RoundingMode::HalfUp)));
            $vat = $gross->minus($net);
        } else {
            $net = $amount;
            $vat = BigDecimal::of(OrdinaryEstimateDecimal::money($net->multipliedBy($rate->withPointMovedLeft(2))));
            $gross = $net->plus($vat);
        }

        return ['net_amount' => (string) $net->toScale(2), 'vat_amount' => (string) $vat->toScale(2),
            'gross_amount' => (string) $gross->toScale(2), 'vat_mode' => $mode,
            'vat_rate' => (string) $rate->strippedOfTrailingZeros(), 'quoted_amount' => (string) $amount];
    }
}

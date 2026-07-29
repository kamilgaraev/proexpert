<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas;

use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\PayrollSourceAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

final readonly class PayrollSourceRateFormula
{
    public function calculate(
        string $hours,
        string $rate,
        string $rateType,
        string $currency,
    ): PayrollSourceAmount {
        $hoursDecimal = BigDecimal::of($hours);
        $rateDecimal = BigDecimal::of($rate);
        if ($hoursDecimal->isLessThan(BigDecimal::zero())
            || $rateDecimal->isLessThan(BigDecimal::zero())) {
            throw new DomainException('PAYROLL_SOURCE_RATE_INVALID');
        }
        if ($rateType !== 'hourly') {
            throw new DomainException('PAYROLL_SOURCE_RATE_TYPE_UNSUPPORTED');
        }
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('PAYROLL_SOURCE_CURRENCY_INVALID');
        }

        return new PayrollSourceAmount(
            rate: (string) $rateDecimal->toScale(4, RoundingMode::Unnecessary),
            currency: $currency,
            amount: (string) $hoursDecimal
                ->multipliedBy($rateDecimal)
                ->toScale(4, RoundingMode::HalfUp),
        );
    }
}

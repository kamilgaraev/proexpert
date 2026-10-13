<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use Brick\Math\BigInteger;
use DomainException;

final class LegacyRefundResolutionArithmetic
{
    /** Unreturned payment must also cover the already consumed value, with no upward money rounding. */
    public static function orderCeiling(int $paidMinor, int $refundedMinor, int $consumedUnitsMinor, int $salePriceMinorPerUnit): int
    {
        if (min($paidMinor, $refundedMinor, $consumedUnitsMinor) < 0 || $salePriceMinorPerUnit < 1 || $refundedMinor > $paidMinor) {
            throw new DomainException('legacy_estimator_refund_resolution_value_invalid');
        }
        $remainingMicro = BigInteger::of($paidMinor - $refundedMinor)->multipliedBy(10000)
            ->minus(BigInteger::of($consumedUnitsMinor)->multipliedBy($salePriceMinorPerUnit)->multipliedBy(100));

        return $remainingMicro->isNegative() ? 0 : $remainingMicro->quotient(10000)->toInt();
    }

    /** All values use the frozen sale rate; the micro-ruble remainder is retained.
     * @return array{units_minor:int,rounding_remainder_micro_rub:int,revoked_units_minor:int}
     */
    public static function resolve(int $remainingCashMinor, int $remainingUnitsMinor, int $salePriceMinorPerUnit,
        int $remainderMicroRub, int $orderCeilingMinor): array
    {
        if (min($remainingCashMinor, $remainingUnitsMinor, $remainderMicroRub, $orderCeilingMinor) < 0
            || $remainingCashMinor > 1_000_000_000_000 || $salePriceMinorPerUnit < 1) {
            throw new DomainException('legacy_estimator_refund_resolution_value_invalid');
        }
        $cash = BigInteger::of($remainingCashMinor)->multipliedBy(10000);
        $unitCost = BigInteger::of($salePriceMinorPerUnit)->multipliedBy(100);
        $availableMoney = BigInteger::of($remainingUnitsMinor)->multipliedBy($unitCost)->plus($remainderMicroRub);
        if ($cash->isGreaterThan($availableMoney) || $remainingCashMinor > $orderCeilingMinor) {
            throw new DomainException('legacy_estimator_refund_resolution_exceeds_ceiling');
        }
        [$units, $remainder] = $cash->quotientAndRemainder($unitCost);
        $unitsMinor = $units->toInt();
        if ($unitsMinor > $remainingUnitsMinor) {
            throw new DomainException('legacy_estimator_refund_resolution_exceeds_ceiling');
        }

        return ['units_minor' => $unitsMinor, 'rounding_remainder_micro_rub' => $remainder->toInt(),
            'revoked_units_minor' => $remainingUnitsMinor - $unitsMinor];
    }
}

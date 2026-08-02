<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use DomainException;

final readonly class ContractSettlementAllocationConserver
{
    /**
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public function allocate(int $totalMinor, array $weights): array
    {
        if ($totalMinor < 0 || $weights === []) {
            throw new DomainException('contract_settlement_allocation_invalid');
        }

        $normalized = [];
        foreach ($weights as $allocationId => $weight) {
            if ($allocationId < 1 || ! is_int($weight) || $weight < 0) {
                throw new DomainException('contract_settlement_allocation_invalid');
            }
            $normalized[$allocationId] = $weight;
        }

        $weightTotal = array_sum($normalized);
        if ($weightTotal <= 0) {
            throw new DomainException('contract_settlement_allocation_invalid');
        }

        ksort($normalized);
        $finalAllocationId = array_key_last($normalized);
        $allocated = [];
        $remaining = $totalMinor;
        foreach ($normalized as $allocationId => $weight) {
            if ($allocationId === $finalAllocationId) {
                $allocated[$allocationId] = $remaining;

                continue;
            }
            if ($weight !== 0 && $totalMinor > intdiv(PHP_INT_MAX, $weight)) {
                throw new DomainException('contract_settlement_allocation_overflow');
            }
            $weighted = $totalMinor * $weight;
            $amount = intdiv($weighted + intdiv($weightTotal, 2), $weightTotal);
            if ($amount > $remaining) {
                throw new DomainException('contract_settlement_allocation_rounding_invalid');
            }
            $allocated[$allocationId] = $amount;
            $remaining -= $amount;
        }

        return $allocated;
    }
}

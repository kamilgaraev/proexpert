<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use DomainException;

final readonly class ContractSettlementAllocationConserver
{
    /**
     * @param array<int, int> $weights
     * @return array<int, int>
     */
    public function allocate(int $totalMinor, array $weights): array
    {
        if ($totalMinor < 0 || $weights === []) {
            throw new DomainException('contract_settlement_allocation_invalid');
        }

        $normalized = [];
        foreach ($weights as $allocationId => $weight) {
            if ($allocationId < 1 || !is_int($weight) || $weight < 0) {
                throw new DomainException('contract_settlement_allocation_invalid');
            }
            $normalized[$allocationId] = $weight;
        }

        $weightTotal = array_sum($normalized);
        if ($weightTotal <= 0) {
            throw new DomainException('contract_settlement_allocation_invalid');
        }

        $allocated = [];
        $remainders = [];
        foreach ($normalized as $allocationId => $weight) {
            if ($weight !== 0 && $totalMinor > intdiv(PHP_INT_MAX, $weight)) {
                throw new DomainException('contract_settlement_allocation_overflow');
            }
            $weighted = $totalMinor * $weight;
            $allocated[$allocationId] = intdiv($weighted, $weightTotal);
            $remainders[$allocationId] = $weighted % $weightTotal;
        }

        $remaining = $totalMinor - array_sum($allocated);
        uksort($remainders, static function (int $left, int $right) use ($remainders): int {
            $comparison = $remainders[$right] <=> $remainders[$left];

            return $comparison !== 0 ? $comparison : $left <=> $right;
        });
        foreach (array_keys($remainders) as $allocationId) {
            if ($remaining === 0) {
                break;
            }
            $allocated[$allocationId]++;
            $remaining--;
        }
        ksort($allocated);

        return $allocated;
    }
}

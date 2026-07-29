<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ComparableProposalVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\SupplierProposalComparabilityPolicy;
use PHPUnit\Framework\TestCase;

final class SupplierProposalComparabilityPolicyTest extends TestCase
{
    public function test_each_changed_comparison_dimension_has_a_stable_exclusion_reason(): void
    {
        $selected = $this->proposal(1);
        $candidates = [
            $selected,
            $this->proposal(2, quantity: '11.000'),
            $this->proposal(3, unit: 'kg'),
            $this->proposal(4, vatBasis: 'excluded'),
            $this->proposal(5, freightBasis: 'excluded'),
            $this->proposal(6, currency: 'USD'),
            $this->proposal(7, specificationHash: str_repeat('b', 64)),
        ];

        $partition = (new SupplierProposalComparabilityPolicy)->partition($candidates, 1);

        self::assertSame([1], array_map(
            static fn (ComparableProposalVersion $proposal): int => $proposal->proposalVersionId,
            $partition->comparable,
        ));
        self::assertSame([
            2 => 'quantity_mismatch',
            3 => 'unit_mismatch',
            4 => 'vat_basis_mismatch',
            5 => 'freight_basis_mismatch',
            6 => 'currency_mismatch',
            7 => 'specification_mismatch',
        ], $partition->excludedReasonByProposalVersionId);
    }

    private function proposal(
        int $id,
        string $quantity = '10.000',
        string $unit = 'piece',
        string $vatBasis = 'included',
        string $freightBasis = 'included',
        string $currency = 'RUB',
        string $specificationHash = '',
    ): ComparableProposalVersion {
        return new ComparableProposalVersion(
            proposalVersionId: $id,
            supplierId: $id,
            amountMinor: 10_000 + $id,
            currency: $currency,
            materialSpecificationHash: $specificationHash === '' ? str_repeat('a', 64) : $specificationHash,
            quantity: $quantity,
            unit: $unit,
            vatBasis: $vatBasis,
            freightBasis: $freightBasis,
        );
    }
}

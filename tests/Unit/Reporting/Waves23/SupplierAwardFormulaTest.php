<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ComparableProposalVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\SupplierAwardFormula;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SupplierAwardFormulaTest extends TestCase
{
    public function test_award_uses_only_comparable_versions(): void
    {
        $metric = (new SupplierAwardFormula)->calculate(
            invitedSupplierIds: [10, 11, 12],
            comparableProposalVersions: [
                $this->proposal(101, 10, 10_000),
                $this->proposal(102, 11, 12_000),
                $this->proposal(103, 12, 8_000, currency: 'USD'),
            ],
            selectedProposalVersionId: 102,
        );

        self::assertSame(2_000, $metric->premiumMinor);
        self::assertSame('0.20000000', $metric->premiumRatio);
        self::assertSame('0.66666667', $metric->participationRatio);
        self::assertSame(hash('sha256', '101,102'), $metric->comparableSetHash);
    }

    public function test_selected_proposal_must_be_part_of_the_comparable_set(): void
    {
        $this->expectException(DomainException::class);

        (new SupplierAwardFormula)->calculate(
            [10, 11],
            [$this->proposal(101, 10, 10_000)],
            999,
        );
    }

    public function test_even_median_preserves_exact_half_minor_unit(): void
    {
        $metric = (new SupplierAwardFormula)->calculate(
            [10, 11],
            [
                $this->proposal(101, 10, 100),
                $this->proposal(102, 11, 101),
            ],
            102,
        );

        self::assertSame('100.5', $metric->medianAmountMinor);
        self::assertSame('0.5', $metric->medianVarianceMinor);
    }

    private function proposal(
        int $id,
        int $supplierId,
        int $amountMinor,
        string $currency = 'RUB',
    ): ComparableProposalVersion {
        return new ComparableProposalVersion(
            proposalVersionId: $id,
            supplierId: $supplierId,
            amountMinor: $amountMinor,
            currency: $currency,
            materialSpecificationHash: str_repeat('a', 64),
            quantity: '10.000',
            unit: 'piece',
            vatBasis: 'included',
            freightBasis: 'included',
            unitDimension: 'count',
            conversionVersion: 'identity-v1',
        );
    }
}

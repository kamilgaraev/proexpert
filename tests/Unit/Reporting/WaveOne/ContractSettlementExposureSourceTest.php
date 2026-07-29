<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Payments\Reporting\SettlementAgingBucket;
use App\BusinessModules\Core\Payments\Services\Reports\SettlementAgingPolicy;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementCalculator;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementInput;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContractSettlementExposureSourceTest extends TestCase
{
    #[Test]
    public function formula_version_is_the_published_contract(): void
    {
        self::assertSame('contracts.settlement-exposure.v1', ContractSettlementCalculator::FORMULA_VERSION);
    }

    #[Test]
    public function accepted_and_completed_cash_are_counted_once_for_an_allocation(): void
    {
        $row = (new ContractSettlementCalculator())->calculate(
            new ContractSettlementInput(
                contractId: 10,
                allocationId: 20,
                projectId: 30,
                partyId: 40,
                direction: 'payable',
                currency: 'RUB',
                effectiveMinor: 100_000,
                acceptedMinor: 50_000,
                cashMinor: 30_000,
                dueAt: new DateTimeImmutable('2026-06-15T00:00:00+03:00'),
                asOf: new DateTimeImmutable('2026-07-26T00:00:00+03:00'),
                sourceRefs: [
                    ['type' => 'approved_act', 'id' => 21],
                    ['type' => 'completed_transaction', 'id' => 31],
                ],
            ),
            new SettlementAgingPolicy(),
        );

        self::assertSame(100_000, $row->effectiveMinor);
        self::assertSame(50_000, $row->acceptedMinor);
        self::assertSame(30_000, $row->cashMinor);
        self::assertSame(20_000, $row->settlementMinor);
        self::assertSame(50_000, $row->unperformedExposureMinor);
        self::assertSame(20_000, $row->unpaidExposureMinor);
        self::assertSame(SettlementAgingBucket::DAYS_31_60->value, $row->agingBucket);
        self::assertSame(
            [
                ['type' => 'approved_act', 'id' => 21],
                ['type' => 'completed_transaction', 'id' => 31],
            ],
            $row->sourceRefs,
        );
    }

    #[Test]
    public function missing_due_date_is_not_silently_classified_as_current(): void
    {
        $row = (new ContractSettlementCalculator())->calculate(
            new ContractSettlementInput(
                contractId: 10,
                allocationId: 20,
                projectId: null,
                partyId: null,
                direction: 'receivable',
                currency: 'USD',
                effectiveMinor: 100_000,
                acceptedMinor: 50_000,
                cashMinor: 30_000,
                dueAt: null,
                asOf: new DateTimeImmutable('2026-07-26T00:00:00+03:00'),
                sourceRefs: [],
            ),
            new SettlementAgingPolicy(),
        );

        self::assertSame('due_date_missing', $row->agingBucket);
    }

    #[Test]
    public function aging_uses_the_explicit_as_of_boundary(): void
    {
        $policy = new SettlementAgingPolicy();
        $asOf = new DateTimeImmutable('2026-07-26T15:30:00+03:00');

        self::assertSame(
            SettlementAgingBucket::NOT_DUE,
            $policy->bucket(new DateTimeImmutable('2026-07-26T00:00:00+03:00'), $asOf),
        );
        self::assertSame(
            SettlementAgingBucket::DAYS_1_30,
            $policy->bucket(new DateTimeImmutable('2026-07-25T00:00:00+03:00'), $asOf),
        );
        self::assertSame(
            SettlementAgingBucket::OVER_90,
            $policy->bucket(new DateTimeImmutable('2026-04-01T00:00:00+03:00'), $asOf),
        );
    }
}

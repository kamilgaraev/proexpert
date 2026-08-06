<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Payments\Reporting\SettlementAgingBucket;
use App\BusinessModules\Core\Payments\Services\Reports\SettlementAgingPolicy;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementAllocationConserver;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementCalculator;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerSource;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementInput;
use App\BusinessModules\Features\ContractManagement\Reporting\Enums\ContractSettlementPartyType;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ContractSettlementExposureSourceTest extends TestCase
{
    public function test_allocation_conservation_assigns_every_minor_once(): void
    {
        $result = (new ContractSettlementAllocationConserver)->allocate(
            totalMinor: 10_001,
            weights: [41 => 50, 42 => 30, 43 => 20],
        );

        self::assertSame([41 => 5_001, 42 => 3_000, 43 => 2_000], $result);
        self::assertSame(10_001, array_sum($result));
    }

    #[Test]
    public function pinned_ninety_ten_shares_conserve_accepted_and_cash_minor_units(): void
    {
        $allocated = (new ContractSettlementAllocationConserver)->allocate(10_001, [101 => 90, 202 => 10]);

        self::assertSame([101 => 9_001, 202 => 1_000], $allocated);
        self::assertSame(10_001, array_sum($allocated));
    }

    public function test_allocation_conservation_rejects_missing_or_negative_weights(): void
    {
        $this->expectException(\DomainException::class);

        (new ContractSettlementAllocationConserver)->allocate(100, [41 => 0, 42 => -1]);
    }

    #[Test]
    public function formula_version_is_the_published_contract(): void
    {
        self::assertSame('contracts.settlement-exposure.v2', ContractSettlementCalculator::FORMULA_VERSION);
    }

    #[Test]
    public function contractor_and_supplier_with_the_same_numeric_id_have_distinct_party_keys(): void
    {
        $contractor = $this->input(ContractSettlementPartyType::CONTRACTOR, 40, 'Подрядчик по договору Д-1');
        $supplier = $this->input(ContractSettlementPartyType::SUPPLIER, 40, 'Поставщик по договору Д-2');

        self::assertSame('contractor:40', $contractor->partyKey());
        self::assertSame('supplier:40', $supplier->partyKey());
        self::assertNotSame($contractor->partyKey(), $supplier->partyKey());
    }

    #[Test]
    public function party_type_and_id_cannot_be_persisted_separately(): void
    {
        $this->expectException(DomainException::class);

        $this->input(null, 40, 'Подрядчик по договору Д-1');
    }

    #[Test]
    public function contract_side_selects_the_correct_party_namespace_when_ids_collide(): void
    {
        $source = new ContractSettlementOwnerSource(new ContractSettlementAllocationConserver);
        $method = new ReflectionMethod($source, 'partyIdentity');
        $contractorContract = new Contract;
        $contractorContract->contractor_id = 40;
        $contractorContract->contract_side_type = ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR;
        self::assertSame(
            [40, ContractSettlementPartyType::CONTRACTOR],
            $method->invoke($source, $contractorContract),
        );

        $supplierContract = new Contract;
        $supplierContract->supplier_id = 40;
        $supplierContract->contract_side_type = ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER;
        self::assertSame(
            [40, ContractSettlementPartyType::SUPPLIER],
            $method->invoke($source, $supplierContract),
        );
    }

    #[Test]
    public function typed_party_filter_never_crosses_party_namespaces(): void
    {
        $source = new ContractSettlementOwnerSource(new ContractSettlementAllocationConserver);
        $method = new ReflectionMethod($source, 'matchesFilters');
        $contractor = $this->input(ContractSettlementPartyType::CONTRACTOR, 40, 'Подрядчик по договору Д-1');
        $supplier = $this->input(ContractSettlementPartyType::SUPPLIER, 40, 'Поставщик по договору Д-2');

        self::assertTrue($method->invoke($source, $contractor, ['party_keys' => ['contractor:40']]));
        self::assertFalse($method->invoke($source, $supplier, ['party_keys' => ['contractor:40']]));
    }

    #[Test]
    public function accepted_and_completed_cash_are_counted_once_for_an_allocation(): void
    {
        $row = (new ContractSettlementCalculator)->calculate(
            new ContractSettlementInput(
                contractId: 10,
                allocationId: 20,
                projectId: 30,
                partyId: 40,
                partyType: ContractSettlementPartyType::CONTRACTOR,
                partyLabel: 'Подрядчик по договору Д-1',
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
            new SettlementAgingPolicy,
        );

        self::assertSame(100_000, $row->effectiveMinor);
        self::assertSame(50_000, $row->acceptedMinor);
        self::assertSame(30_000, $row->cashMinor);
        self::assertSame(20_000, $row->settlementMinor);
        self::assertSame(50_000, $row->unperformedExposureMinor);
        self::assertSame(20_000, $row->unpaidExposureMinor);
        self::assertSame('Подрядчик по договору Д-1', $row->partyLabel);
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
        $row = (new ContractSettlementCalculator)->calculate(
            new ContractSettlementInput(
                contractId: 10,
                allocationId: 20,
                projectId: null,
                partyId: null,
                partyType: null,
                partyLabel: 'Заказчик по договору Д-3',
                direction: 'receivable',
                currency: 'USD',
                effectiveMinor: 100_000,
                acceptedMinor: 50_000,
                cashMinor: 30_000,
                dueAt: null,
                asOf: new DateTimeImmutable('2026-07-26T00:00:00+03:00'),
                sourceRefs: [],
            ),
            new SettlementAgingPolicy,
        );

        self::assertSame('due_date_missing', $row->agingBucket);
    }

    #[Test]
    public function aging_uses_the_explicit_as_of_boundary(): void
    {
        $policy = new SettlementAgingPolicy;
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

    private function input(
        ?ContractSettlementPartyType $partyType,
        ?int $partyId,
        string $partyLabel,
    ): ContractSettlementInput
    {
        return new ContractSettlementInput(
            contractId: 10,
            allocationId: 20,
            projectId: 30,
            partyId: $partyId,
            partyType: $partyType,
            partyLabel: $partyLabel,
            direction: 'payable',
            currency: 'RUB',
            effectiveMinor: 100_000,
            acceptedMinor: 50_000,
            cashMinor: 30_000,
            dueAt: null,
            asOf: new DateTimeImmutable('2026-07-26T00:00:00+03:00'),
            sourceRefs: [],
        );
    }
}

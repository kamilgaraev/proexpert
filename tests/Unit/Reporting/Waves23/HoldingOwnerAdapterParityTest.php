<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingContractDimensionSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingHierarchyResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceFormula;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HoldingOwnerAdapterParityTest extends TestCase
{
    #[Test]
    public function fixed_and_percentage_allocations_match_hand_calculated_owner_fixture(): void
    {
        $projector = new HoldingAllocationFactProjector(new HoldingHierarchyResolver);

        $fixed = $projector->project([
            'organization_id' => 1,
            'holding_id' => 5,
            'hierarchy_version' => hash('sha256', 'hierarchy-v1'),
            'hierarchy_organization_ids' => [5, 10, 11],
            'contributor_organization_id' => 10,
            'project_id' => 100,
            'contract_id' => 50,
            'contractor_id' => 500,
            'contract_status' => 'active',
            'work_type_category' => 'smr',
            'contract_dimension_hash' => hash('sha256', 'contract-dimension-50'),
            'allocation_id' => 70,
            'source_type' => 'contract',
            'source_id' => 50,
            'source_version' => 3,
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => 12_500,
            'allocated_percentage' => null,
            'contract_amount_minor' => 50_000,
            'currency' => 'RUB',
            'currency_source' => 'contract',
            'tax_basis' => 'contract_total',
            'recognized_on' => '2026-07-29',
            'flow_class' => 'internal',
        ]);
        $percentage = $projector->project([
            'organization_id' => 1,
            'holding_id' => 5,
            'hierarchy_version' => hash('sha256', 'hierarchy-v1'),
            'hierarchy_organization_ids' => [5, 10, 11],
            'contributor_organization_id' => 11,
            'project_id' => 100,
            'contract_id' => 50,
            'contractor_id' => 500,
            'contract_status' => 'active',
            'work_type_category' => 'smr',
            'contract_dimension_hash' => hash('sha256', 'contract-dimension-50'),
            'allocation_id' => 71,
            'source_type' => 'contract',
            'source_id' => 71,
            'source_version' => 3,
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => null,
            'allocated_percentage' => '25.00000000',
            'contract_amount_minor' => 50_000,
            'currency' => 'RUB',
            'currency_source' => 'contract',
            'tax_basis' => 'contract_total',
            'recognized_on' => '2026-07-29',
            'flow_class' => 'internal',
        ]);

        self::assertInstanceOf(HoldingAllocationFact::class, $fixed);
        self::assertSame(12_500, $fixed->amountMinor);
        self::assertSame(12_500, $percentage->amountMinor);
        self::assertNotSame($fixed->sourceKey(), $percentage->sourceKey());
    }

    #[Test]
    public function flow_class_uses_only_the_pinned_hierarchy_membership(): void
    {
        $projector = new HoldingAllocationFactProjector(new HoldingHierarchyResolver);

        self::assertSame('internal', $projector->classify(10, 11, [10, 11, 12]));
        self::assertSame('external', $projector->classify(10, 99, [10, 11, 12]));
        self::assertSame('unclassified', $projector->classify(98, 99, [10, 11, 12]));
        self::assertSame('unclassified', $projector->classify(10, null, [10, 11, 12]));
    }

    #[Test]
    public function unsupported_three_letter_currency_is_preserved_as_unknown_fact_quality(): void
    {
        $dimension = new HoldingContractDimensionSnapshot(
            eventId: 81,
            contractId: 50,
            organizationId: 10,
            contractorId: 500,
            counterpartyOrganizationId: null,
            contractStatus: 'active',
            workTypeCategory: 'smr',
            totalAmount: '500.00',
            rawCurrency: 'GBP',
            currency: null,
            evidenceHash: hash('sha256', 'contract-dimension-gbp'),
            coverageStartedAt: '2026-08-05T00:00:00+00:00',
        );
        $fact = (new HoldingAllocationFactProjector(new HoldingHierarchyResolver))->project([
            'organization_id' => 10,
            'holding_id' => 5,
            'hierarchy_version' => hash('sha256', 'hierarchy-v1'),
            'hierarchy_organization_ids' => [5, 10],
            'contributor_organization_id' => 10,
            'project_id' => 100,
            'contract_id' => 50,
            'contractor_id' => 500,
            'contract_status' => 'active',
            'work_type_category' => 'smr',
            'contract_dimension_hash' => $dimension->evidenceHash,
            'allocation_id' => 70,
            'source_type' => 'contract_checkpoint',
            'source_id' => 70,
            'source_version' => 3,
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => 12_500,
            'allocated_percentage' => null,
            'contract_amount_minor' => 50_000,
            'currency' => $dimension->currency,
            'currency_source' => 'unknown_contract_dimension_checkpoint',
            'tax_basis' => 'contract_total',
            'recognized_on' => '2026-08-05',
        ]);

        self::assertSame('GBP', $dimension->rawCurrency);
        self::assertNull($dimension->currency);
        self::assertNull($fact->currency);
        self::assertSame(12_500, $fact->amountMinor);
        $formula = new HoldingPerformanceFormula;
        $totals = $formula->totals([$formula->row($fact)]);
        self::assertSame([], $totals['currencies']);
        self::assertSame(1, $totals['quality']['unknown_currency_count']);
        self::assertNull($totals['quality']['excluded_amount_minor']);
    }

    #[Test]
    public function incomplete_currency_or_hierarchy_evidence_never_creates_a_fact_dto(): void
    {
        $projector = new HoldingAllocationFactProjector(new HoldingHierarchyResolver);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('holding_allocation_evidence_missing');
        $projector->project([
            'organization_id' => 1,
            'source_type' => 'contract',
            'source_id' => 70,
            'source_version' => 3,
            'monetary_basis' => 'contracted',
        ]);
    }

    #[Test]
    public function different_unsupported_currency_amounts_are_never_aggregated(): void
    {
        $gbp = $this->unsupportedCurrencyFact('GBP', 91, 12_500);
        $cny = $this->unsupportedCurrencyFact('CNY', 92, 20_000);
        $formula = new HoldingPerformanceFormula;
        $totals = $formula->totals([$formula->row($gbp), $formula->row($cny)]);

        self::assertSame('GBP', $gbp->sourceRefs[0]['currency_code']);
        self::assertSame('CNY', $cny->sourceRefs[0]['currency_code']);
        self::assertSame([], $totals['currencies']);
        self::assertSame(2, $totals['quality']['unknown_currency_count']);
        self::assertNull($totals['quality']['excluded_amount_minor']);
    }

    #[Test]
    public function checkpoint_source_accepts_canonical_keyed_evidence(): void
    {
        $fact = HoldingAllocationFact::contracted(5, 10, 100, 'RUB', 12_500);
        $evidence = [
            'source_type' => 'contract_checkpoint',
            'source_id' => $fact->sourceId,
            'source_version' => $fact->sourceVersion,
        ];

        $source = new HoldingAllocationCheckpointSource(
            $fact,
            $evidence,
            hash('sha256', 'checkpoint-source'),
        );

        self::assertSame($evidence, $source->evidence);
    }

    private function unsupportedCurrencyFact(string $rawCurrency, int $sourceId, int $amountMinor): HoldingAllocationFact
    {
        return new HoldingAllocationFact(
            organizationId: 5,
            holdingId: 5,
            hierarchyVersion: hash('sha256', 'hierarchy-v1'),
            contributorOrganizationId: 10,
            counterpartyOrganizationId: null,
            projectId: 100,
            contractId: $sourceId + 100,
            contractorId: null,
            contractStatus: 'active',
            workTypeCategory: null,
            contractDimensionHash: hash('sha256', 'dimension-'.$sourceId),
            allocationId: $sourceId,
            linkedParentAllocationId: null,
            linkedIncomingMinor: null,
            linkedOutgoingMinor: null,
            sourceType: 'contract_checkpoint',
            sourceId: $sourceId,
            sourceVersion: 1,
            monetaryBasis: 'contracted',
            amountMinor: $amountMinor,
            currency: null,
            currencySource: 'unknown_contract_dimension_checkpoint',
            taxBasis: 'contract_total',
            recognizedOn: '2026-08-05',
            flowClass: 'unclassified',
            sourceRefs: [[
                'type' => 'contract_dimension',
                'currency_code' => $rawCurrency,
            ]],
        );
    }
}

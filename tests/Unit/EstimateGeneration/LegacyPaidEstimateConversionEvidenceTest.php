<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\LegacyPaidEstimateConversionEvidence;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\OrganizationResourceAllocation;
use PHPUnit\Framework\TestCase;

final class LegacyPaidEstimateConversionEvidenceTest extends TestCase
{
    public function test_the_shared_snapshot_hash_preserves_the_existing_wallet_contract(): void
    {
        [$allocation, $entry] = $this->fixture();
        self::assertSame(EstimatorCreditPolicy::allocationHash($allocation), LegacyPaidEstimateConversionEvidence::allocationHash($allocation));
        self::assertTrue(LegacyPaidEstimateConversionEvidence::validated($allocation, $entry));
        self::assertSame('10.00', $allocation->quantity);
    }

    public function test_another_organization_and_a_changed_allocation_cannot_use_the_receipt(): void
    {
        [$allocation, $entry] = $this->fixture();
        $entry->organization_id = 99;
        self::assertFalse(LegacyPaidEstimateConversionEvidence::validated($allocation, $entry));
        $entry->organization_id = 2;
        $allocation->quantity = '11.00';
        self::assertFalse(LegacyPaidEstimateConversionEvidence::validated($allocation, $entry));
    }

    public function test_conversion_arithmetic_and_the_grant_type_are_validated(): void
    {
        [$allocation, $entry] = $this->fixture();
        $metadata = $entry->metadata;
        $metadata['converted_units_minor'] = 601;
        $entry->forceFill(['metadata' => $metadata, 'amount_minor' => 601]);
        self::assertFalse(LegacyPaidEstimateConversionEvidence::validated($allocation, $entry));
        [$allocation, $entry] = $this->fixture();
        $entry->type = 'consume';
        self::assertFalse(LegacyPaidEstimateConversionEvidence::validated($allocation, $entry));
    }

    public function test_zero_remainder_is_valid_but_unknown_sources_and_overflow_are_not(): void
    {
        [$allocation, $entry] = $this->fixture();
        $metadata = $entry->metadata;
        $metadata['remaining_paid_amount_minor'] = 0;
        $metadata['converted_units_minor'] = 0;
        $entry->forceFill(['metadata' => $metadata, 'amount_minor' => 0]);
        self::assertTrue(LegacyPaidEstimateConversionEvidence::validated($allocation, $entry));
        $allocation->source = 'manual_grant';
        self::assertFalse(LegacyPaidEstimateConversionEvidence::validated($allocation, $entry));
        [$allocation, $entry] = $this->fixture();
        $metadata = $entry->metadata;
        $metadata['remaining_paid_amount_minor'] = PHP_INT_MAX;
        $entry->metadata = $metadata;
        self::assertFalse(LegacyPaidEstimateConversionEvidence::validated($allocation, $entry));
    }

    public function test_a_restoration_marker_must_match_the_original_grant_organization_lot_and_amount(): void
    {
        [, $grant] = $this->fixture();
        $grant->forceFill(['id' => 11, 'reference_type' => 'lot', 'reference_id' => '32']);
        $restored = new AICreditLedgerEntry;
        $restored->forceFill(['organization_id' => 2, 'idempotency_key' => $grant->idempotency_key.':restored',
            'type' => 'conversion_reversal', 'amount_minor' => -600, 'reference_type' => 'lot', 'reference_id' => '32',
            'metadata' => ['original_grant_id' => 11, 'remaining_money_proof_hash' => $grant->metadata['remaining_money_proof_hash']]]);
        self::assertTrue(LegacyPaidEstimateConversionEvidence::isRestorationOf($grant, $restored));
        $restored->organization_id = 3;
        self::assertFalse(LegacyPaidEstimateConversionEvidence::isRestorationOf($grant, $restored));
        $restored->forceFill(['organization_id' => 2, 'reference_id' => '33']);
        self::assertFalse(LegacyPaidEstimateConversionEvidence::isRestorationOf($grant, $restored));
        $restored->forceFill(['reference_id' => '32', 'amount_minor' => -599]);
        self::assertFalse(LegacyPaidEstimateConversionEvidence::isRestorationOf($grant, $restored));
    }

    private function fixture(): array
    {
        $allocation = new OrganizationResourceAllocation;
        $allocation->forceFill(['id' => 7, 'organization_id' => 2, 'commercial_account_id' => null,
            'resource_slug' => 'extra_ai_estimates', 'limit_key' => 'ai_estimates_month', 'quantity' => '10.00',
            'source' => 'paid_addon', 'period_start_at' => null, 'period_end_at' => null,
            'metadata' => ['commercial_order_id' => 'order-fixture', 'amount_minor' => 1000, 'currency' => 'RUB']]);
        $entry = new AICreditLedgerEntry;
        $entry->forceFill(['organization_id' => 2, 'idempotency_key' => 'legacy-estimator-paid-addon:7', 'type' => 'grant',
            'amount_minor' => 600, 'metadata' => ['migration_version' => 'estimator-paid:v1', 'allocation_id' => 7,
                'allocation_snapshot_hash' => LegacyPaidEstimateConversionEvidence::allocationHash($allocation),
                'converted_units_minor' => 600, 'remaining_paid_amount_minor' => 600,
                'commercial_sale_price_minor_per_unit' => 100, 'commercial_price_version' => 2,
                'remaining_money_proof_hash' => hash('sha256', 'verified remaining money fixture')]]);

        return [$allocation, $entry];
    }
}

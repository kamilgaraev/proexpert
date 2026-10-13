<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\LegacyEstimatorReconciliationProof;
use PHPUnit\Framework\TestCase;

final class LegacyEstimatorReconciliationProofTest extends TestCase
{
    public function test_an_immutable_attestation_must_match_the_ledger_and_organization(): void
    {
        [$row, $metadata] = $this->fixture();
        self::assertTrue(LegacyEstimatorReconciliationProof::matches($row, $metadata, 2, 7));
        self::assertFalse(LegacyEstimatorReconciliationProof::matches($row, $metadata, 3, 7));
        $metadata['remaining_money_proof_hash'] = hash('sha256', 'another statement');
        self::assertFalse(LegacyEstimatorReconciliationProof::matches($row, $metadata, 2, 7));
    }

    public function test_even_a_consistently_rehashed_statement_cannot_invent_a_rounding_remainder(): void
    {
        [$row, $metadata] = $this->fixture();
        $proof = json_decode($row->proof, true, 512, JSON_THROW_ON_ERROR);
        $proof['conversion']['rounding_remainder_micro_rub'] = 100;
        $row->rounding_remainder_micro_rub = 100;
        $row->proof = CanonicalPipelineJson::encode($proof);
        $row->proof_hash = hash('sha256', $row->proof);
        $metadata['remaining_money_proof_hash'] = $row->proof_hash;
        self::assertFalse(LegacyEstimatorReconciliationProof::matches($row, $metadata, 2, 7));
    }

    public function test_the_attested_remaining_value_cannot_exceed_the_verified_purchase(): void
    {
        [$row, $metadata] = $this->fixture();
        $proof = json_decode($row->proof, true, 512, JSON_THROW_ON_ERROR);
        $proof['purchase']['allocation_paid_amount_minor'] = 599;
        $row->purchase_snapshot_hash = hash('sha256', CanonicalPipelineJson::encode($proof['purchase']));
        $row->proof = CanonicalPipelineJson::encode($proof);
        $row->proof_hash = hash('sha256', $row->proof);
        $metadata['remaining_money_proof_hash'] = $row->proof_hash;
        self::assertFalse(LegacyEstimatorReconciliationProof::matches($row, $metadata, 2, 7));
    }

    private function fixture(): array
    {
        $allocationHash = hash('sha256', 'owned allocation');
        $sale = ['source' => 'commercial_credit_pack', 'pack_id' => 'ai-credits-1000', 'commercial_price_version' => 2,
            'unit_minor' => 100, 'pack_units_minor' => 100000, 'pack_amount_minor' => 100000, 'currency' => 'RUB'];
        $purchase = ['commercial_order_id' => 11, 'organization_id' => 2, 'allocation_hash' => $allocationHash,
            'allocation_paid_amount_minor' => 1000];
        $proof = ['version' => 1, 'verification_method' => 'finance_admin_attestation', 'organization_id' => 2,
            'allocation_id' => 7, 'system_admin_id' => 5, 'allocation_hash' => $allocationHash, 'currency' => 'RUB',
            'evidence_reference' => 'FINANCE-001', 'evidence_sha256' => hash('sha256', 'finance statement'), 'reason' => 'Verified remaining paid value.',
            'purchase' => $purchase, 'sale_price' => $sale, 'sale_price_hash' => hash('sha256', CanonicalPipelineJson::encode($sale)),
            'remaining_paid_amount_minor' => 600, 'conversion' => ['units_minor' => 600, 'rounding_remainder_micro_rub' => 0]];
        $row = (object) ['id' => 1, 'organization_id' => 2, 'allocation_id' => 7, 'system_admin_id' => 5,
            'commercial_order_id' => 11, 'allocation_snapshot_hash' => $allocationHash,
            'purchase_snapshot_hash' => hash('sha256', CanonicalPipelineJson::encode($purchase)),
            'proof' => CanonicalPipelineJson::encode($proof), 'sale_price_snapshot' => CanonicalPipelineJson::encode($sale),
            'proof_hash' => hash('sha256', CanonicalPipelineJson::encode($proof)),
            'evidence_reference' => $proof['evidence_reference'], 'evidence_sha256' => $proof['evidence_sha256'], 'reason' => $proof['reason'],
            'remaining_paid_amount_minor' => 600, 'converted_units_minor' => 600, 'rounding_remainder_micro_rub' => 0];
        $metadata = ['reconciliation_id' => 1, 'allocation_snapshot_hash' => $allocationHash,
            'remaining_money_proof_hash' => $row->proof_hash, 'commercial_price_version' => 2,
            'commercial_sale_price_minor_per_unit' => 100, 'remaining_paid_amount_minor' => 600, 'converted_units_minor' => 600];

        return [$row, $metadata];
    }
}

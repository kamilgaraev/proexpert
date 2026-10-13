<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Billing;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

/** A formatted hash alone is not evidence: the ledger must match the immutable financial attestation. */
final class LegacyEstimatorReconciliationProof
{
    public const TABLE = 'estimate_generation_legacy_reconciliations';

    public static function matches(object $row, array $metadata, int $organizationId, int $allocationId): bool
    {
        try {
            $proof = json_decode((string) $row->proof, true, 512, JSON_THROW_ON_ERROR);
            $sale = json_decode((string) $row->sale_price_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($proof) || ! is_array($sale) || ! is_array($proof['purchase'] ?? null)
                || ($proof['verification_method'] ?? null) !== 'finance_admin_attestation'
                || ($proof['version'] ?? null) !== 1 || ($proof['currency'] ?? null) !== 'RUB'
                || ($metadata['reconciliation_id'] ?? null) !== (int) $row->id
                || (int) $row->organization_id !== $organizationId || (int) $row->allocation_id !== $allocationId
                || ($proof['organization_id'] ?? null) !== $organizationId || ($proof['allocation_id'] ?? null) !== $allocationId
                || ($proof['system_admin_id'] ?? null) !== (int) $row->system_admin_id
                || ($proof['allocation_hash'] ?? null) !== $row->allocation_snapshot_hash
                || ($metadata['allocation_snapshot_hash'] ?? null) !== $row->allocation_snapshot_hash
                || ($metadata['remaining_money_proof_hash'] ?? null) !== $row->proof_hash
                || ($proof['evidence_reference'] ?? null) !== $row->evidence_reference
                || ($proof['evidence_sha256'] ?? null) !== $row->evidence_sha256
                || ($proof['reason'] ?? null) !== $row->reason
                || ($proof['purchase']['commercial_order_id'] ?? null) !== (int) $row->commercial_order_id
                || ($proof['purchase']['organization_id'] ?? null) !== $organizationId
                || ($proof['purchase']['allocation_hash'] ?? null) !== $row->allocation_snapshot_hash
                || hash('sha256', CanonicalPipelineJson::encode($proof['purchase'] ?? [])) !== $row->purchase_snapshot_hash
                || hash('sha256', CanonicalPipelineJson::encode($proof)) !== $row->proof_hash
                || ($proof['sale_price'] ?? null) !== $sale
                || ($proof['sale_price_hash'] ?? null) !== hash('sha256', CanonicalPipelineJson::encode($sale))
                || ($sale['source'] ?? null) !== 'commercial_credit_pack' || ($sale['currency'] ?? null) !== 'RUB'
                || ($sale['unit_minor'] ?? null) !== 100
                || ($sale['commercial_price_version'] ?? null) !== ($metadata['commercial_price_version'] ?? null)
                || ! is_int($sale['pack_units_minor'] ?? null) || $sale['pack_units_minor'] < 100
                || ! is_int($sale['pack_amount_minor'] ?? null) || $sale['pack_amount_minor'] < 1
                || $sale['pack_amount_minor'] > intdiv(PHP_INT_MAX, 100)
                || ($sale['pack_amount_minor'] * 100) % $sale['pack_units_minor'] !== 0
                || intdiv($sale['pack_amount_minor'] * 100, $sale['pack_units_minor']) !== ($metadata['commercial_sale_price_minor_per_unit'] ?? null)
                || ($metadata['remaining_paid_amount_minor'] ?? null) !== (int) $row->remaining_paid_amount_minor
                || ($proof['remaining_paid_amount_minor'] ?? null) !== (int) $row->remaining_paid_amount_minor
                || ($metadata['converted_units_minor'] ?? null) !== (int) $row->converted_units_minor
                || ($proof['conversion']['units_minor'] ?? null) !== (int) $row->converted_units_minor
                || ($proof['conversion']['rounding_remainder_micro_rub'] ?? null) !== (int) $row->rounding_remainder_micro_rub) {
                return false;
            }

            $remaining = $proof['remaining_paid_amount_minor'];
            $paid = $proof['purchase']['allocation_paid_amount_minor'] ?? null;
            $price = $metadata['commercial_sale_price_minor_per_unit'] ?? null;
            if (! is_int($remaining) || $remaining < 0 || $remaining > 1_000_000_000_000
                || ! is_int($paid) || $paid < $remaining || $paid > 1_000_000_000_000
                || ! is_int($price) || $price < 1) {
                return false;
            }
            $units = intdiv($remaining * 100, $price);

            return $units === (int) $row->converted_units_minor
                && $remaining * 10000 - $units * $price * 100 === (int) $row->rounding_remainder_micro_rub;
        } catch (\JsonException|\InvalidArgumentException) {
            return false;
        }
    }
}

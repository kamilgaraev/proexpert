<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\OrganizationResourceAllocation;

/** Unknown legacy balances keep their existing quota and billing path until a proven conversion is recorded. */
final class EstimatorCreditPolicy
{
    public static function enabledForOrganization(int $organizationId): bool
    {
        if ($organizationId < 1 || ! config('estimate-generation.credits.enabled', false)) {
            return false;
        }
        foreach (OrganizationResourceAllocation::query()->where('organization_id', $organizationId)
            ->where('limit_key', 'ai_estimates_month')->where('quantity', '>', 0)->active()->orderBy('id')->get() as $allocation) {
            $entry = AICreditLedgerEntry::query()->where('organization_id', $organizationId)
                ->where('idempotency_key', 'legacy-estimator-paid-addon:'.$allocation->id)->first();
            $metadata = $entry?->metadata ?? [];
            if ($allocation->source !== 'paid_addon' || $entry === null || ($metadata['migration_version'] ?? null) !== 'estimator-paid:v1'
                || ($metadata['allocation_id'] ?? null) !== (int) $allocation->id
                || ($metadata['allocation_snapshot_hash'] ?? null) !== self::allocationHash($allocation)
                || ! is_int($metadata['converted_units_minor'] ?? null) || $metadata['converted_units_minor'] < 0
                || (int) $entry->amount_minor !== $metadata['converted_units_minor']
                || ! is_int($metadata['remaining_paid_amount_minor'] ?? null) || $metadata['remaining_paid_amount_minor'] < 0
                || ! is_int($metadata['commercial_sale_price_minor_per_unit'] ?? null) || $metadata['commercial_sale_price_minor_per_unit'] < 1
                || ! is_int($metadata['commercial_price_version'] ?? null) || $metadata['commercial_price_version'] < 1
                || ! is_string($metadata['remaining_money_proof_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $metadata['remaining_money_proof_hash']) !== 1) {
                return false;
            }
        }

        return true;
    }

    public static function allocationHash(OrganizationResourceAllocation $allocation): string
    {
        return hash('sha256', CanonicalPipelineJson::encode([
            'allocation_id' => (int) $allocation->id, 'organization_id' => (int) $allocation->organization_id,
            'commercial_account_id' => $allocation->commercial_account_id === null ? null : (int) $allocation->commercial_account_id,
            'resource_slug' => $allocation->resource_slug, 'limit_key' => $allocation->limit_key, 'quantity' => $allocation->quantity,
            'source' => $allocation->source, 'period_start_at' => $allocation->period_start_at?->toAtomString(),
            'period_end_at' => $allocation->period_end_at?->toAtomString(),
            'commercial_order_id' => $allocation->metadata['commercial_order_id'] ?? null,
            'amount_minor' => $allocation->metadata['amount_minor'] ?? null, 'currency' => $allocation->metadata['currency'] ?? null,
        ]));
    }
}

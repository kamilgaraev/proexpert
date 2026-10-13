<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Billing;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\OrganizationResourceAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Public boundary shared by wallet eligibility and the legacy quota; historical allocations stay unchanged. */
final class LegacyPaidEstimateConversionEvidence
{
    /** @param Collection<int,OrganizationResourceAllocation> $allocations
     * @return Collection<int,OrganizationResourceAllocation>
     */
    public static function unconverted(Collection $allocations): Collection
    {
        $candidates = $allocations->filter(static fn (OrganizationResourceAllocation $allocation): bool => $allocation->source === 'paid_addon' && $allocation->limit_key === 'ai_estimates_month');
        if ($candidates->isEmpty()) {
            return $allocations;
        }
        $keys = $candidates->flatMap(static fn (OrganizationResourceAllocation $allocation): array => [
            'legacy-estimator-paid-addon:'.$allocation->id, 'legacy-estimator-paid-addon:'.$allocation->id.':restored'])->values()->all();
        $entries = AICreditLedgerEntry::query()->whereIn('organization_id', $candidates->pluck('organization_id')->unique()->all())
            ->whereIn('idempotency_key', $keys)->get()
            ->keyBy(static fn (AICreditLedgerEntry $entry): string => $entry->organization_id.'|'.$entry->idempotency_key);

        if (! $entries->contains(static fn (AICreditLedgerEntry $entry): bool => is_int($entry->metadata['reconciliation_id'] ?? null))
            || ! Schema::hasTable(LegacyEstimatorReconciliationProof::TABLE)) {
            return $allocations;
        }

        $proofs = DB::table(LegacyEstimatorReconciliationProof::TABLE)
            ->whereIn('organization_id', $candidates->pluck('organization_id')->unique()->all())
            ->whereIn('allocation_id', $candidates->pluck('id')->all())->get()
            ->keyBy(static fn (object $row): string => $row->organization_id.'|'.$row->allocation_id);

        return $allocations->reject(static function (OrganizationResourceAllocation $allocation) use ($entries, $proofs): bool {
            $entry = $entries->get($allocation->organization_id.'|legacy-estimator-paid-addon:'.$allocation->id);
            $restored = $entries->get($allocation->organization_id.'|legacy-estimator-paid-addon:'.$allocation->id.':restored');
            $proof = $proofs->get($allocation->organization_id.'|'.$allocation->id);
            if ($entry !== null && self::isRestorationOf($entry, $restored)) {
                return false;
            }

            return self::validated($allocation, $entry) && $proof !== null
                && LegacyEstimatorReconciliationProof::matches($proof, $entry->metadata, (int) $allocation->organization_id, (int) $allocation->id);
        });
    }

    public static function isRestorationOf(AICreditLedgerEntry $grant, ?AICreditLedgerEntry $restored): bool
    {
        return $restored !== null && (int) $restored->organization_id === (int) $grant->organization_id
            && $restored->idempotency_key === $grant->idempotency_key.':restored'
            && $restored->type === 'conversion_reversal'
            && (int) $restored->amount_minor === -(int) $grant->amount_minor
            && $restored->reference_type === 'lot' && $grant->reference_type === 'lot'
            && $restored->reference_id === $grant->reference_id
            && ($restored->metadata['original_grant_id'] ?? null) === (int) $grant->id
            && ($restored->metadata['remaining_money_proof_hash'] ?? null) === ($grant->metadata['remaining_money_proof_hash'] ?? null);
    }

    public static function validated(OrganizationResourceAllocation $allocation, ?AICreditLedgerEntry $entry): bool
    {
        $metadata = $entry?->metadata ?? [];

        return $allocation->source === 'paid_addon' && $allocation->limit_key === 'ai_estimates_month'
            && $entry !== null && (int) $entry->organization_id === (int) $allocation->organization_id
            && $entry->idempotency_key === 'legacy-estimator-paid-addon:'.$allocation->id
            && $entry->type === 'grant'
            && ($metadata['migration_version'] ?? null) === 'estimator-paid:v1'
            && ($metadata['allocation_id'] ?? null) === (int) $allocation->id
            && ($metadata['allocation_snapshot_hash'] ?? null) === self::allocationHash($allocation)
            && is_int($metadata['converted_units_minor'] ?? null) && $metadata['converted_units_minor'] >= 0
            && (int) $entry->amount_minor === $metadata['converted_units_minor']
            && is_int($metadata['remaining_paid_amount_minor'] ?? null) && $metadata['remaining_paid_amount_minor'] >= 0
            && is_int($metadata['commercial_sale_price_minor_per_unit'] ?? null) && $metadata['commercial_sale_price_minor_per_unit'] > 0
            && is_int($metadata['commercial_price_version'] ?? null) && $metadata['commercial_price_version'] > 0
            && is_string($metadata['remaining_money_proof_hash'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $metadata['remaining_money_proof_hash']) === 1
            && $metadata['remaining_paid_amount_minor'] <= intdiv(PHP_INT_MAX, 100)
            && intdiv($metadata['remaining_paid_amount_minor'] * 100, $metadata['commercial_sale_price_minor_per_unit'])
                === $metadata['converted_units_minor'];
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

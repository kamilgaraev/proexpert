<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Billing;

use App\Models\Credits\AICreditLedgerEntry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Called while holding the organization lock, before publishing a refund intent. */
final class LegacyEstimatorRefundGuard
{
    public static function assertAllowed(int $organizationId, int $orderId): void
    {
        if (! Schema::hasTable(LegacyEstimatorReconciliationProof::TABLE)) {
            return;
        }
        // Expired allocations are included: their converted credits may still be spendable.
        $proofs = DB::table(LegacyEstimatorReconciliationProof::TABLE)->where('organization_id', $organizationId)
            ->where('commercial_order_id', $orderId)->where('converted_units_minor', '>', 0)->get();
        foreach ($proofs as $proof) {
            $key = 'legacy-estimator-paid-addon:'.$proof->allocation_id;
            $grant = AICreditLedgerEntry::query()->where('organization_id', $organizationId)->where('idempotency_key', $key)->first();
            $restored = AICreditLedgerEntry::query()->where('organization_id', $organizationId)->where('idempotency_key', $key.':restored')->first();
            if ($grant === null || $grant->type !== 'grant' || (int) $grant->amount_minor !== (int) $proof->converted_units_minor
                || ! LegacyEstimatorReconciliationProof::matches($proof, $grant->metadata ?? [], $organizationId, (int) $proof->allocation_id)
                || ! LegacyPaidEstimateConversionEvidence::isRestorationOf($grant, $restored)) {
                throw new DomainException(trans_message('billing.refund.estimator_conversion_requires_reconciliation'));
            }
        }
    }
}

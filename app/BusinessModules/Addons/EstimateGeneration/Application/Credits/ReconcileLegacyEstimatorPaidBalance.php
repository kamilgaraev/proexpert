<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\LegacyEstimatorReconciliationProof;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\LegacyPaidEstimateConversionEvidence;
use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\Filament\Support\FilamentPermission;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationResourceAllocation;
use App\Models\SystemAdmin;
use App\Services\Credits\AICreditService;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ReconcileLegacyEstimatorPaidBalance
{
    public const TABLE = LegacyEstimatorReconciliationProof::TABLE;

    public function __construct(private AICreditService $credits, private ImmutableAuditRecorder $audit) {}

    /** Preview proves the purchase ceiling; it cannot infer a paid remainder from mixed quota counters.
     * @return array{allocation_hash:string,purchase_hash:string,paid_amount_minor:int,currency:string,sale_price:array<string,mixed>,sale_price_hash:string}
     */
    public function preview(int $actorId, int $organizationId, int $allocationId, string $salePackId): array
    {
        $this->actor($actorId);
        $allocation = $this->allocation($organizationId, $allocationId);
        $purchase = LegacyEstimatorPurchaseEvidence::capture($allocation);
        $sale = LegacyEstimatorSalePriceSnapshot::capture($salePackId);

        return ['allocation_hash' => LegacyPaidEstimateConversionEvidence::allocationHash($allocation),
            'purchase_hash' => $purchase->fingerprint(), 'paid_amount_minor' => $purchase->paidAmountMinor, 'currency' => 'RUB',
            'sale_price' => $sale->snapshot, 'sale_price_hash' => $sale->fingerprint()];
    }

    /** @return array{reconciliation_id:int,converted_units_minor:int,rounding_remainder_micro_rub:int,idempotent_replay:bool} */
    public function confirm(int $actorId, LegacyEstimatorReconciliationData $data): array
    {
        $this->actor($actorId);
        $commandHash = hash('sha256', CanonicalPipelineJson::encode(['actor_id' => $actorId, ...get_object_vars($data)]));

        return DB::transaction(function () use ($actorId, $data, $commandHash): array {
            $actor = $this->actor($actorId, true);
            // Legacy reserve takes this same organization lock before reading quota and writing its reservation.
            $organization = Organization::query()->whereKey($data->organizationId)->lockForUpdate()->firstOrFail();
            $existing = DB::table(self::TABLE)->where('organization_id', $data->organizationId)
                ->where(function ($query) use ($data): void {
                    $query->where('allocation_id', $data->allocationId)->orWhere('request_id', $data->requestId);
                })->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->command_hash, $commandHash)) {
                    throw new DomainException('legacy_estimator_reconciliation_request_changed');
                }

                return $this->receipt($existing, true);
            }
            if (! config('estimate-generation.credits.legacy_reconciliation_enabled', false)
                || ! config('estimate-generation.credits.enabled', false)) {
                throw new DomainException('legacy_estimator_reconciliation_disabled');
            }
            $allocation = $this->allocation($data->organizationId, $data->allocationId, true);
            $hash = LegacyPaidEstimateConversionEvidence::allocationHash($allocation);
            if (! hash_equals($hash, $data->expectedAllocationHash)) {
                throw new DomainException('legacy_estimator_allocation_changed');
            }
            if (DB::table('estimate_generation_ai_estimate_quota_reservations')->where('organization_id', $data->organizationId)
                ->where('status', 'reserved')->exists()) {
                throw new DomainException('legacy_estimator_inflight_quota_requires_completion');
            }
            $purchase = LegacyEstimatorPurchaseEvidence::capture($allocation, true);
            if (! hash_equals($purchase->fingerprint(), $data->expectedPurchaseHash)) {
                throw new DomainException('legacy_estimator_purchase_changed');
            }
            if ($data->remainingPaidAmountMinor > $purchase->paidAmountMinor) {
                throw new DomainException('legacy_estimator_remaining_money_exceeds_purchase');
            }
            $key = 'legacy-estimator-paid-addon:'.$data->allocationId;
            if (AICreditLedgerEntry::query()->where('organization_id', $data->organizationId)->where('idempotency_key', $key)->exists()) {
                throw new DomainException('legacy_estimator_existing_conversion_requires_reconciliation');
            }
            $sale = LegacyEstimatorSalePriceSnapshot::capture($data->salePackId);
            if (! hash_equals($sale->fingerprint(), $data->expectedSalePriceHash)) {
                throw new DomainException('legacy_estimator_sale_price_changed');
            }
            $converted = $sale->convert($data->remainingPaidAmountMinor);
            $proof = ['version' => 1, 'verification_method' => 'finance_admin_attestation',
                'organization_id' => $data->organizationId, 'allocation_id' => $data->allocationId, 'allocation_hash' => $hash,
                'purchase' => $purchase->snapshot, 'remaining_paid_amount_minor' => $data->remainingPaidAmountMinor,
                'currency' => 'RUB', 'evidence_reference' => $data->evidenceReference, 'evidence_sha256' => $data->evidenceSha256,
                'reason' => $data->reason, 'system_admin_id' => $actorId, 'sale_price' => $sale->snapshot,
                'sale_price_hash' => $sale->fingerprint(), 'conversion' => $converted];
            $proofHash = hash('sha256', CanonicalPipelineJson::encode($proof));
            $id = DB::table(self::TABLE)->insertGetId(['organization_id' => $data->organizationId, 'allocation_id' => $data->allocationId,
                'commercial_order_id' => $purchase->order->id, 'system_admin_id' => $actorId, 'request_id' => $data->requestId,
                'command_hash' => $commandHash, 'allocation_snapshot_hash' => $hash, 'purchase_snapshot_hash' => $purchase->fingerprint(),
                'proof_hash' => $proofHash, 'proof' => CanonicalPipelineJson::encode($proof),
                'sale_price_snapshot' => CanonicalPipelineJson::encode($sale->snapshot),
                'remaining_paid_amount_minor' => $data->remainingPaidAmountMinor, 'converted_units_minor' => $converted['units_minor'],
                'rounding_remainder_micro_rub' => $converted['rounding_remainder_micro_rub'],
                'evidence_reference' => $data->evidenceReference, 'evidence_sha256' => $data->evidenceSha256, 'reason' => $data->reason, 'created_at' => now()]);
            $metadata = ['migration_version' => 'estimator-paid:v1', 'allocation_id' => $data->allocationId,
                'allocation_snapshot_hash' => $hash, 'converted_units_minor' => $converted['units_minor'],
                'remaining_paid_amount_minor' => $data->remainingPaidAmountMinor,
                'commercial_sale_price_minor_per_unit' => $sale->salePriceMinorPerUnit,
                'commercial_price_version' => $sale->snapshot['commercial_price_version'],
                'remaining_money_proof_hash' => $proofHash, 'reconciliation_id' => $id, 'sale_price_snapshot' => $sale->snapshot];
            if ($converted['units_minor'] === 0) {
                $this->credits->grantZeroConversion($organization, $key, (int) $purchase->order->id, $metadata);
            } else {
                $this->credits->grant($organization, $converted['units_minor'], 'conversion', null, $key, (int) $purchase->order->id, $metadata);
            }
            $this->actor($actorId);
            $this->audit->record(new ImmutableAuditEventData(organizationId: $data->organizationId, domain: 'payments',
                eventType: 'legacy_estimator_balance_reconciled', action: 'confirm', source: 'estimate_generation',
                actorType: 'system_admin', actorSnapshot: ['system_admin_id' => $actorId, 'role' => $actor->role],
                sourceEventId: $data->requestId, idempotencyKey: $key, subjectType: 'organization_resource_allocation',
                subjectId: $data->allocationId, reason: $data->reason,
                beforeState: ['legacy_quantity' => $allocation->quantity, 'allocation_hash' => $hash],
                afterState: ['reconciliation_id' => $id, 'converted_units_minor' => $converted['units_minor'], 'proof_hash' => $proofHash],
                domainContext: ['commercial_order_id' => (int) $purchase->order->id, 'verification_method' => 'finance_admin_attestation']));

            return ['reconciliation_id' => $id, 'converted_units_minor' => $converted['units_minor'],
                'rounding_remainder_micro_rub' => $converted['rounding_remainder_micro_rub'], 'idempotent_replay' => false];
        }, 1);
    }

    private function actor(int $id, bool $lock = false): SystemAdmin
    {
        $query = SystemAdmin::query()->whereKey($id);
        $actor = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($actor === null || ! $actor->hasSystemPermission(FilamentPermission::PAYMENTS_MANAGE)) {
            throw new DomainException('legacy_estimator_financial_permission_required');
        }

        return $actor;
    }

    private function allocation(int $organizationId, int $id, bool $lock = false): OrganizationResourceAllocation
    {
        $query = OrganizationResourceAllocation::query()->whereKey($id)->where('organization_id', $organizationId)
            ->where('source', 'paid_addon')->where('limit_key', 'ai_estimates_month')->where('quantity', '>', 0)->active();

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** @return array{reconciliation_id:int,converted_units_minor:int,rounding_remainder_micro_rub:int,idempotent_replay:bool} */
    private function receipt(object $row, bool $replay): array
    {
        return ['reconciliation_id' => (int) $row->id, 'converted_units_minor' => (int) $row->converted_units_minor,
            'rounding_remainder_micro_rub' => (int) $row->rounding_remainder_micro_rub, 'idempotent_replay' => $replay];
    }
}

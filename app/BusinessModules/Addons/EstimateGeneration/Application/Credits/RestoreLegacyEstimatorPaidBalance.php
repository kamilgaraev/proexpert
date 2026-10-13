<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

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
use Illuminate\Support\Str;

final readonly class RestoreLegacyEstimatorPaidBalance
{
    public function __construct(private AICreditService $credits, private ImmutableAuditRecorder $audit) {}

    /** Restores the old billing path only when the entire converted lot remains unused.
     * @return array{reconciliation_id:int,reversed_units_minor:int,idempotent_replay:bool}
     */
    public function handle(int $actorId, int $organizationId, int $reconciliationId, string $requestId, string $reason): array
    {
        if (! Str::isUuid($requestId) || trim($reason) === '' || strlen($reason) > 2000 || preg_match('/[\x00-\x1F]/', $reason) === 1) {
            throw new DomainException('legacy_estimator_restore_input_invalid');
        }
        $this->actor($actorId);

        return DB::transaction(function () use ($actorId, $organizationId, $reconciliationId, $requestId, $reason): array {
            $actor = $this->actor($actorId);
            $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $row = DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $reconciliationId)
                ->where('organization_id', $organizationId)->lockForUpdate()->first();
            if ($row === null) {
                throw new DomainException('legacy_estimator_restore_scope_invalid');
            }
            $key = 'legacy-estimator-paid-addon:'.$row->allocation_id;
            // The credit service acquires wallet before grant; keep that order for all credit mutations.
            $grant = AICreditLedgerEntry::query()->where('organization_id', $organizationId)->where('idempotency_key', $key)->firstOrFail();
            if (! LegacyEstimatorReconciliationProof::matches($row, $grant->metadata ?? [], $organizationId, (int) $row->allocation_id)) {
                throw new DomainException('legacy_estimator_restore_proof_changed');
            }
            $restoreKey = $key.':restored';
            $existing = AICreditLedgerEntry::query()->where('organization_id', $organizationId)->where('idempotency_key', $restoreKey)->first();
            if ($existing === null) {
                $allocation = OrganizationResourceAllocation::query()->whereKey($row->allocation_id)
                    ->where('organization_id', $organizationId)->active()->lockForUpdate()->first();
                if ($allocation === null || LegacyPaidEstimateConversionEvidence::allocationHash($allocation) !== $row->allocation_snapshot_hash) {
                    throw new DomainException('legacy_estimator_restore_allocation_changed_or_expired');
                }
                if (LegacyEstimatorPurchaseEvidence::capture($allocation, true)->fingerprint() !== $row->purchase_snapshot_hash) {
                    throw new DomainException('legacy_estimator_restore_purchase_changed');
                }
            }
            $metadata = ['request_id' => $requestId, 'system_admin_id' => $actorId, 'original_grant_id' => (int) $grant->id,
                'reconciliation_id' => (int) $row->id, 'remaining_money_proof_hash' => $row->proof_hash, 'reason' => $reason];
            $this->credits->reverseUnusedConversion($organization, (int) $grant->id, $restoreKey, $metadata);
            $this->actor($actorId);
            $this->audit->record(new ImmutableAuditEventData(organizationId: $organizationId, domain: 'payments',
                eventType: 'legacy_estimator_balance_restored', action: 'restore', source: 'estimate_generation',
                actorType: 'system_admin', actorSnapshot: ['system_admin_id' => $actorId, 'role' => $actor->role],
                sourceEventId: $requestId, idempotencyKey: $restoreKey, subjectType: 'organization_resource_allocation',
                subjectId: (int) $row->allocation_id, reason: $reason,
                beforeState: ['reconciliation_id' => (int) $row->id, 'converted_units_minor' => (int) $row->converted_units_minor],
                afterState: ['reversed_units_minor' => (int) $row->converted_units_minor, 'legacy_billing_restored' => true],
                domainContext: ['proof_hash' => $row->proof_hash]));

            return ['reconciliation_id' => (int) $row->id, 'reversed_units_minor' => (int) $row->converted_units_minor,
                'idempotent_replay' => $existing !== null];
        }, 1);
    }

    private function actor(int $id): SystemAdmin
    {
        $actor = SystemAdmin::query()->whereKey($id)->lockForUpdate()->first();
        if ($actor === null || ! $actor->hasSystemPermission(FilamentPermission::PAYMENTS_MANAGE)) {
            throw new DomainException('legacy_estimator_financial_permission_required');
        }

        return $actor;
    }
}

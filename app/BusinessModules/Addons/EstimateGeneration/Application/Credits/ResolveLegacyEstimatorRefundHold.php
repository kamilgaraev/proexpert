<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\LegacyRefundResolutionArithmetic;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\LegacyEstimatorReconciliationProof;
use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\Filament\Support\FilamentPermission;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditReservation;
use App\Models\Credits\AICreditReservationAllocation;
use App\Models\Credits\AICreditWallet;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Services\Credits\AICreditService;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ResolveLegacyEstimatorRefundHold
{
    public const TABLE = 'estimate_generation_legacy_refund_resolutions';

    public function __construct(private AICreditService $credits, private ImmutableAuditRecorder $audit) {}

    /** @return array<int,int> Reconciliation IDs and held units for the authorized organization. */
    public function candidates(int $actorId, int $organizationId): array
    {
        $this->actor($actorId);
        $lots = AICreditLot::query()->where('organization_id', $organizationId)->where('source', 'conversion')
            ->where('metadata->'.AICreditLot::LEGACY_REFUND_HOLD, true)->get();
        $ids = $lots->map(fn ($lot) => $lot->metadata['reconciliation_id'] ?? null)->filter()->all();
        $rows = DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('organization_id', $organizationId)->whereIn('id', $ids)->get();
        $result = [];
        foreach ($rows as $row) {
            $lot = $lots->first(fn ($lot) => ($lot->metadata['reconciliation_id'] ?? null) === (int) $row->id);
            if ($lot !== null) {
                $result[(int) $row->id] = (int) $lot->remaining_minor;
            }
        }

        return $result;
    }

    /** The preview is a ceiling, not an inferred attribution of the refund.
     * @return array{snapshot_hash:string,maximum_paid_amount_minor:int,remaining_units_minor:int,cumulative_refund_amount_minor:int}
     */
    public function preview(int $actorId, int $organizationId, int $reconciliationId): array
    {
        return DB::transaction(function () use ($actorId, $organizationId, $reconciliationId): array {
            $this->actor($actorId);
            $state = $this->state($organizationId, $reconciliationId);
            $maximum = min($state['order_ceiling_minor'], intdiv($state['remaining_units_minor'] * $state['sale_price_minor_per_unit'] * 100
                + $state['remainder_micro_rub'], 10000));

            return ['snapshot_hash' => $this->hash($state), 'maximum_paid_amount_minor' => $maximum,
                'remaining_units_minor' => $state['remaining_units_minor'], 'cumulative_refund_amount_minor' => $state['refund_minor']];
        }, 1);
    }

    /** @return array{resolution_id:int,remaining_units_minor:int,revoked_units_minor:int,rounding_remainder_micro_rub:int,idempotent_replay:bool} */
    public function confirm(int $actorId, LegacyEstimatorRefundResolutionData $data): array
    {
        $commandHash = hash('sha256', CanonicalPipelineJson::encode(['actor_id' => $actorId, ...get_object_vars($data)]));

        return DB::transaction(function () use ($actorId, $data, $commandHash): array {
            $actor = $this->actor($actorId);
            $organization = Organization::query()->whereKey($data->organizationId)->lockForUpdate()->firstOrFail();
            $existing = DB::table(self::TABLE)->where('organization_id', $data->organizationId)->where('request_id', $data->requestId)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->command_hash, $commandHash)) {
                    throw new DomainException('legacy_estimator_refund_resolution_request_changed');
                }

                return $this->receipt($existing, true);
            }
            if (! config('estimate-generation.credits.legacy_reconciliation_enabled', false)) {
                throw new DomainException('legacy_estimator_reconciliation_disabled');
            }
            $state = $this->state($data->organizationId, $data->reconciliationId);
            if (! hash_equals($this->hash($state), $data->expectedSnapshotHash)) {
                throw new DomainException('legacy_estimator_refund_resolution_snapshot_changed');
            }
            $calculation = LegacyRefundResolutionArithmetic::resolve($data->remainingPaidAmountMinor, $state['remaining_units_minor'],
                $state['sale_price_minor_per_unit'], $state['remainder_micro_rub'], $state['order_ceiling_minor']);
            $proof = ['version' => 1, 'verification_method' => 'finance_admin_refund_attestation', 'currency' => 'RUB',
                'system_admin_id' => $actorId, 'state' => $state, 'remaining_paid_amount_minor' => $data->remainingPaidAmountMinor,
                'calculation' => $calculation, 'evidence_reference' => $data->evidenceReference,
                'evidence_sha256' => $data->evidenceSha256, 'reason' => $data->reason];
            $proofHash = hash('sha256', CanonicalPipelineJson::encode($proof));
            $id = DB::table(self::TABLE)->insertGetId(['organization_id' => $data->organizationId,
                'reconciliation_id' => $data->reconciliationId, 'ai_credit_lot_id' => $state['lot_id'],
                'commercial_payment_id' => $state['payment_id'], 'system_admin_id' => $actorId, 'request_id' => $data->requestId,
                'command_hash' => $commandHash, 'snapshot_hash' => $data->expectedSnapshotHash, 'proof_hash' => $proofHash,
                'proof' => CanonicalPipelineJson::encode($proof), 'cumulative_refund_amount_minor' => $state['refund_minor'],
                'remaining_paid_amount_minor' => $data->remainingPaidAmountMinor, 'remaining_units_minor' => $calculation['units_minor'],
                'revoked_units_minor' => $calculation['revoked_units_minor'], 'rounding_remainder_micro_rub' => $calculation['rounding_remainder_micro_rub'],
                'created_at' => now()]);
            $key = 'legacy-estimator-refund-resolution:'.$data->requestId;
            $this->credits->resolveLegacyRefundHold($organization, $state['lot_id'], $state['remaining_units_minor'], $state['refund_minor'],
                $calculation['units_minor'], $key, ['resolution_id' => $id, 'proof_hash' => $proofHash,
                    'reconciliation_id' => $data->reconciliationId, 'cumulative_refund_amount_minor' => $state['refund_minor'],
                    'rounding_remainder_micro_rub' => $calculation['rounding_remainder_micro_rub']]);
            $this->actor($actorId);
            $this->audit->record(new ImmutableAuditEventData(organizationId: $data->organizationId, domain: 'payments',
                eventType: 'legacy_estimator_refund_resolved', action: 'resolve', source: 'estimate_generation',
                actorType: 'system_admin', actorSnapshot: ['system_admin_id' => $actorId, 'role' => $actor->role],
                sourceEventId: $data->requestId, idempotencyKey: $key, subjectType: 'ai_credit_lot', subjectId: $state['lot_id'],
                reason: $data->reason, beforeState: $state,
                afterState: ['resolution_id' => $id, 'remaining_units_minor' => $calculation['units_minor'], 'proof_hash' => $proofHash],
                domainContext: ['reconciliation_id' => $data->reconciliationId, 'original_proof_hash' => $state['original_proof_hash']]));

            return ['resolution_id' => $id, 'remaining_units_minor' => $calculation['units_minor'],
                'revoked_units_minor' => $calculation['revoked_units_minor'], 'rounding_remainder_micro_rub' => $calculation['rounding_remainder_micro_rub'],
                'idempotent_replay' => false];
        }, 1);
    }

    /** @return array{organization_id:int,reconciliation_id:int,original_proof_hash:string,payment_id:int,payment_amount_minor:int,refund_minor:int,order_ceiling_minor:int,lot_id:int,remaining_units_minor:int,original_units_minor:int,consumed_units_minor:int,previous_revoked_units_minor:int,sale_price_minor_per_unit:int,remainder_micro_rub:int} */
    private function state(int $organizationId, int $reconciliationId): array
    {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
        $row = DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('organization_id', $organizationId)->where('id', $reconciliationId)->first();
        if ($row === null) {
            throw new DomainException('legacy_estimator_refund_resolution_scope_invalid');
        }
        // Match refund webhook: payment before order, then wallet before lot.
        $payments = CommercialPayment::query()->where('commercial_order_id', $row->commercial_order_id)->lockForUpdate()->get();
        $order = CommercialOrder::query()->whereKey($row->commercial_order_id)->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
        $payment = $payments->first();
        if ($payments->count() !== 1 || $payment === null || $payment->currency !== 'RUB' || $order->currency !== 'RUB'
            || $payment->provider_status !== 'succeeded' || $payment->reconciliation_required
            || $payment->amount_minor !== $order->amount_minor || $payment->refunded_amount_minor < 1
            || $payment->refunded_amount_minor > $payment->amount_minor
            || CommercialRefund::query()->where('commercial_order_id', $order->id)->where(function ($query): void {
                $query->whereNotIn('provider_status', ['succeeded', 'canceled'])->orWhere('reconciliation_required', true);
            })->exists()) {
            throw new DomainException('legacy_estimator_refund_resolution_payment_unknown');
        }
        $row = DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $reconciliationId)->lockForUpdate()->first();
        $wallet = AICreditWallet::query()->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
        $grant = AICreditLedgerEntry::query()->where('organization_id', $organizationId)
            ->where('idempotency_key', 'legacy-estimator-paid-addon:'.$row->allocation_id)->firstOrFail();
        if (! LegacyEstimatorReconciliationProof::matches($row, $grant->metadata ?? [], $organizationId, (int) $row->allocation_id)
            || $grant->type !== 'grant' || $grant->reference_type !== 'lot') {
            throw new DomainException('legacy_estimator_refund_resolution_proof_changed');
        }
        $lot = AICreditLot::query()->whereKey($grant->reference_id)->where('organization_id', $organizationId)
            ->where('commercial_order_id', $order->id)->lockForUpdate()->firstOrFail();
        if ($lot->source !== 'conversion' || ($lot->metadata[AICreditLot::LEGACY_REFUND_HOLD] ?? false) !== true
            || ($lot->metadata['legacy_estimator_refund_amount_minor'] ?? null) !== $payment->refunded_amount_minor
            || (int) $lot->original_minor !== (int) $row->converted_units_minor
            || $lot->expires_at !== null || (int) $lot->remaining_minor > (int) $wallet->balance_minor
            || AICreditLot::query()->where('commercial_order_id', $order->id)->where('id', '<>', $lot->id)
                ->where('source', 'conversion')->exists()) {
            // Multiple allocations require a joint attestation, never independent use of the whole order ceiling.
            throw new DomainException('legacy_estimator_refund_resolution_joint_or_lot_changed');
        }
        if (AICreditReservation::query()->where('organization_id', $organizationId)->where('status', 'reserved')
            ->whereHas('allocations', fn ($query) => $query->where('ai_credit_lot_id', $lot->id))->exists()) {
            throw new DomainException('legacy_estimator_refund_resolution_active_reserve');
        }
        $consumed = (int) AICreditReservationAllocation::query()->where('ai_credit_lot_id', $lot->id)->sum('consumed_minor');
        $resolutions = DB::table(self::TABLE)->where('organization_id', $organizationId)->where('ai_credit_lot_id', $lot->id)->orderBy('id')->get();
        $corrections = AICreditLedgerEntry::query()->where('organization_id', $organizationId)->where('type', 'hold_resolution')
            ->where('reference_type', 'lot')->where('reference_id', (string) $lot->id)->get();
        if ($resolutions->count() !== $corrections->count()) {
            throw new DomainException('legacy_estimator_refund_resolution_history_unknown');
        }
        foreach ($resolutions as $resolution) {
            $proof = json_decode((string) $resolution->proof, true, 512, JSON_THROW_ON_ERROR);
            $entry = $corrections->first(fn ($entry) => ($entry->metadata['resolution_id'] ?? null) === (int) $resolution->id);
            if (! is_array($proof) || hash('sha256', CanonicalPipelineJson::encode($proof)) !== $resolution->proof_hash
                || ($proof['state']['original_proof_hash'] ?? null) !== $row->proof_hash
                || ($proof['calculation']['units_minor'] ?? null) !== (int) $resolution->remaining_units_minor
                || ($proof['calculation']['revoked_units_minor'] ?? null) !== (int) $resolution->revoked_units_minor
                || ($proof['calculation']['rounding_remainder_micro_rub'] ?? null) !== (int) $resolution->rounding_remainder_micro_rub
                || $entry === null || (int) $entry->amount_minor !== -(int) $resolution->revoked_units_minor
                || ($entry->metadata['proof_hash'] ?? null) !== $resolution->proof_hash) {
                throw new DomainException('legacy_estimator_refund_resolution_history_unknown');
            }
        }
        $last = $resolutions->last();
        if (($last !== null && (($lot->metadata['legacy_refund_resolution_id'] ?? null) !== (int) $last->id
                || ($lot->metadata['legacy_resolution_remainder_micro_rub'] ?? null) !== (int) $last->rounding_remainder_micro_rub))
            || ($last === null && (isset($lot->metadata['legacy_refund_resolution_id']) || isset($lot->metadata['legacy_resolution_remainder_micro_rub'])))) {
            throw new DomainException('legacy_estimator_refund_resolution_history_unknown');
        }
        $revoked = (int) $resolutions->sum('revoked_units_minor');
        if ((int) $lot->original_minor !== (int) $lot->remaining_minor + $consumed + $revoked) {
            throw new DomainException('legacy_estimator_refund_resolution_history_unknown');
        }

        return ['organization_id' => $organizationId, 'reconciliation_id' => $reconciliationId, 'original_proof_hash' => (string) $row->proof_hash,
            'payment_id' => (int) $payment->id, 'payment_amount_minor' => (int) $payment->amount_minor,
            'refund_minor' => (int) $payment->refunded_amount_minor,
            'order_ceiling_minor' => LegacyRefundResolutionArithmetic::orderCeiling((int) $payment->amount_minor,
                (int) $payment->refunded_amount_minor, $consumed, (int) $grant->metadata['commercial_sale_price_minor_per_unit']),
            'lot_id' => (int) $lot->id, 'remaining_units_minor' => (int) $lot->remaining_minor, 'original_units_minor' => (int) $lot->original_minor,
            'consumed_units_minor' => $consumed, 'previous_revoked_units_minor' => $revoked,
            'sale_price_minor_per_unit' => (int) $grant->metadata['commercial_sale_price_minor_per_unit'],
            'remainder_micro_rub' => (int) ($lot->metadata['legacy_resolution_remainder_micro_rub'] ?? $row->rounding_remainder_micro_rub)];
    }

    private function actor(int $id): SystemAdmin
    {
        $actor = SystemAdmin::query()->whereKey($id)->lockForUpdate()->first();
        if ($actor === null || ! $actor->hasSystemPermission(FilamentPermission::PAYMENTS_MANAGE)) {
            throw new DomainException('legacy_estimator_financial_permission_required');
        }

        return $actor;
    }

    private function hash(array $state): string
    {
        return hash('sha256', CanonicalPipelineJson::encode($state));
    }

    private function receipt(object $row, bool $replay): array
    {
        return ['resolution_id' => (int) $row->id, 'remaining_units_minor' => (int) $row->remaining_units_minor,
            'revoked_units_minor' => (int) $row->revoked_units_minor, 'rounding_remainder_micro_rub' => (int) $row->rounding_remainder_micro_rub,
            'idempotent_replay' => $replay];
    }
}

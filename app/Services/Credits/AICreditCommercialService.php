<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\CommercialOrder;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditReservation;
use App\Models\Credits\AICreditWallet;
use App\Models\Organization;
use App\Services\Entitlements\OrganizationEntitlementService;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AICreditCommercialService
{
    public function __construct(private readonly AICreditService $credits, private readonly OrganizationEntitlementService $entitlements) {}

    public function settlePaidOrder(CommercialOrder $order): void
    {
        if ($order->status->value !== 'paid') { throw new DomainException('AI credits require a paid order.'); }
        $organization = Organization::query()->findOrFail($order->organization_id);
        if ($order->kind === 'ai_credits') {
            $pack = $order->selected_resource_addons[0] ?? [];
            if ((int) ($pack['amount_minor'] ?? 0) !== (int) $order->amount_minor || (int) ($pack['units_minor'] ?? 0) <= 0) { throw new DomainException('Invalid AI credit paid pack.'); }
            $this->credits->grant($organization, (int) $pack['units_minor'], 'purchase', null, 'purchase:'.$order->public_id, (int) $order->getKey(), ['pack_id' => $pack['slug'], 'amount_minor' => (int) $order->amount_minor]);
            return;
        }
        if ($order->period_end_at <= now() || ! $this->entitlements->hasModuleAccess((int) $order->organization_id, 'ai-assistant')) { return; }
        $periodKey = 'paid-period:'.$order->commercial_account_id.':'.$order->period_start_at->toAtomString();
        if (AICreditLedgerEntry::query()->where('organization_id', $order->organization_id)->whereIn('idempotency_key', [$periodKey, 'legacy-base:'.$order->commercial_account_id.':'.$order->period_start_at->toAtomString()])->exists()) { return; }
        $this->credits->grant($organization, 500_000, 'subscription', $order->period_end_at, $periodKey, (int) $order->getKey(), ['period_start_at' => $order->period_start_at->toAtomString(), 'period_end_at' => $order->period_end_at->toAtomString()]);
    }

    public function refund(CommercialOrder $order, int $cumulativeAmountMinor): void
    {
        DB::transaction(function () use ($order, $cumulativeAmountMinor): void {
            AICreditWallet::query()->insertOrIgnore(['organization_id' => $order->organization_id, 'balance_minor' => 0, 'reserved_minor' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $wallet = AICreditWallet::query()->where('organization_id', $order->organization_id)->lockForUpdate()->firstOrFail();
            $lots = AICreditLot::query()->where('organization_id', $order->organization_id)->where('commercial_order_id', $order->getKey())->lockForUpdate()->get();
            foreach ($lots as $lot) {
                $key = 'refund:'.$order->public_id.':'.$lot->getKey().':'.$cumulativeAmountMinor;
                if (AICreditLedgerEntry::query()->where('organization_id', $order->organization_id)->where('idempotency_key', $key)->exists()) { continue; }
                $metadata = (array) $lot->metadata;
                $target = min((int) $lot->original_minor, intdiv((int) $lot->original_minor * min((int) $order->amount_minor, $cumulativeAmountMinor), max(1, (int) $order->amount_minor)));
                $previous = (int) ($metadata['refunded_units_minor'] ?? 0);
                if ($target <= $previous) { continue; }
                $reservations = AICreditReservation::query()->where('organization_id', $order->organization_id)->where('status', 'reserved')->whereHas('allocations', fn ($query) => $query->where('ai_credit_lot_id', $lot->getKey()))->lockForUpdate()->get();
                foreach ($reservations as $reservation) { $this->credits->cancel($reservation); }
                $lot->refresh();
                $revoke = min($target - $previous, (int) $lot->remaining_minor);
                $lot->forceFill(['remaining_minor' => (int) $lot->remaining_minor - $revoke, 'metadata' => $metadata])->save();
                $metadata['refunded_units_minor'] = $target;
                $lot->forceFill(['metadata' => $metadata])->save();
                $wallet->refresh();
                $wallet->decrement('balance_minor', $revoke);
                $wallet->refresh();
                AICreditLedgerEntry::query()->create(['organization_id' => $order->organization_id, 'type' => 'refund', 'amount_minor' => -$revoke, 'balance_after_minor' => $wallet->balance_minor, 'reference_type' => 'commercial_order', 'reference_id' => $order->public_id, 'idempotency_key' => $key, 'metadata' => ['amount_minor' => $cumulativeAmountMinor, 'refunded_units_minor' => $target - $previous, 'already_consumed_minor' => $target - $previous - $revoke]]);
            }
        }, 3);
    }
}

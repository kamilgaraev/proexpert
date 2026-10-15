<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Enums\Billing\CommercialBillingPeriod;
use App\Models\CommercialOrder;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditReservation;
use App\Models\Credits\AICreditWallet;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AICreditCommercialService
{
    public function __construct(private readonly AICreditService $credits, private readonly OrganizationEntitlementService $entitlements) {}

    public function settlePaidOrder(CommercialOrder $order): void
    {
        if ($order->status->value !== 'paid') {
            throw new DomainException('AI credits require a paid order.');
        }
        $organization = Organization::query()->findOrFail($order->organization_id);
        if ($order->kind === 'ai_credits') {
            $pack = $order->selected_resource_addons[0] ?? [];
            if ((int) ($pack['amount_minor'] ?? 0) !== (int) $order->amount_minor || (int) ($pack['units_minor'] ?? 0) <= 0) {
                throw new DomainException('Invalid AI credit paid pack.');
            }
            $this->credits->grant($organization, (int) $pack['units_minor'], 'purchase', null, 'purchase:'.$order->public_id, (int) $order->getKey(), ['pack_id' => $pack['slug'], 'amount_minor' => (int) $order->amount_minor]);

            return;
        }
        $window = $this->subscriptionWindow($order);
        if ($window === null) {
            return;
        }
        [$periodStart, $periodEnd] = $window;
        if (! $this->entitlements->hasModuleAccess((int) $order->organization_id, 'ai-assistant')
            && ! $this->entitlements->hasModuleAccess((int) $order->organization_id, 'ai-estimates')) {
            return;
        }
        $periodKey = 'paid-period:'.$order->commercial_account_id.':'.$periodStart->toAtomString();
        if (AICreditLedgerEntry::query()->where('organization_id', $order->organization_id)->whereIn('idempotency_key', [$periodKey, 'legacy-base:'.$order->commercial_account_id.':'.$periodStart->toAtomString()])->exists()) {
            return;
        }
        DB::transaction(function () use ($order, $organization, $periodStart, $periodEnd, $periodKey): void {
            $this->credits->grant($organization, 500_000, 'subscription', $periodEnd, $periodKey, (int) $order->getKey(), ['period_start_at' => $periodStart->toAtomString(), 'period_end_at' => $periodEnd->toAtomString()]);
            // Read after acquiring the wallet lock so a concurrent refund cannot miss this new lot.
            $refundedAmount = (int) $order->payments()->sum('refunded_amount_minor');
            if ($refundedAmount > 0) {
                $this->refund($order, $refundedAmount);
            }
        }, 3);
    }

    /** Reconcile a newly available AI product against an already paid period, never against a pending order. */
    public function reconcileCurrentPaidPeriod(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            $account = OrganizationCommercialAccount::query()->where('organization_id', $organization->id)->lockForUpdate()->first();
            if ($account === null || $account->status->value !== 'active' || $account->current_period_start_at === null
                || $account->current_period_end_at === null || $account->current_period_end_at <= now()) {
                return;
            }
            $order = $this->currentPaidAiOrder($account);
            if ($order !== null) {
                $this->settlePaidOrder($order);
            }
        }, 3);
    }

    private function currentPaidAiOrder(OrganizationCommercialAccount $account): ?CommercialOrder
    {
        $catalog = app(PackageCatalogService::class);
        $aiPackages = array_filter(array_column($catalog->allPackages(), 'slug'), fn (string $slug): bool =>
            array_intersect(['ai-assistant', 'ai-estimates'], $catalog->tierModules($slug, 'standard')) !== []);
        $subscriptions = OrganizationPackageSubscription::query()->where('organization_id', $account->organization_id)
            ->where('commercial_account_id', $account->id)->whereIn('package_slug', $aiPackages)
            ->whereIn('access_source', ['paid_package', 'full_suite'])->whereIn('status', ['active', 'scheduled_for_removal'])
            ->where('current_period_end_at', '>', now())->orderByDesc('current_period_end_at')->orderBy('id')->get();
        $orders = [];
        foreach ($subscriptions as $subscription) {
            $sourceId = (int) $subscription->source_order_id;
            $visited = [];
            while ($sourceId > 0 && ! isset($visited[$sourceId])) {
                $visited[$sourceId] = true;
                if (! array_key_exists($sourceId, $orders)) {
                    $orders[$sourceId] = CommercialOrder::query()->whereKey($sourceId)->where('organization_id', $account->organization_id)
                        ->where('commercial_account_id', $account->id)->where('status', 'paid')->where('kind', '<>', 'ai_credits')->first();
                }
                $order = $orders[$sourceId];
                if ($order === null || $order->period_start_at === null || $order->period_end_at === null) {
                    break;
                }
                if ($order->period_start_at <= now() && $order->period_end_at > now()) {
                    return $order;
                }
                if (! $order->renew_subscription || $order->period_start_at <= now()) {
                    break;
                }
                // A prepaid next period points back to the still-paid source for this same AI package.
                $sourceId = (int) ($order->paid_period_snapshot['package_order_ids'][$subscription->package_slug] ?? 0);
            }
        }

        return null;
    }

    private function subscriptionWindow(CommercialOrder $order): ?array
    {
        $now = CarbonImmutable::instance(now());
        if ($order->period_start_at === null || $order->period_end_at === null
            || $order->period_start_at->greaterThan($now) || $order->period_end_at->lessThanOrEqualTo($now)) {
            return null;
        }
        $term = CommercialBillingPeriod::from($order->billing_period ?? '30_days');
        if ($term === CommercialBillingPeriod::ThirtyDays) {
            return [$order->period_start_at, $order->period_end_at];
        }
        $anchor = $order->period_start_at->setTimezone('Europe/Moscow');
        $timezone = $order->period_start_at->getTimezone();
        for ($month = 0; $month < $term->months(); $month++) {
            $start = $anchor->addMonthsNoOverflow($month)->setTimezone($timezone);
            $end = $anchor->addMonthsNoOverflow($month + 1)->setTimezone($timezone)->min($order->period_end_at);
            if ($start->lessThanOrEqualTo($now) && $end->greaterThan($now)) {
                return [$start, $end];
            }
        }

        return null;
    }

    public function refund(CommercialOrder $order, int $cumulativeAmountMinor): void
    {
        DB::transaction(function () use ($order, $cumulativeAmountMinor): void {
            AICreditWallet::query()->insertOrIgnore(['organization_id' => $order->organization_id, 'balance_minor' => 0, 'reserved_minor' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $wallet = AICreditWallet::query()->where('organization_id', $order->organization_id)->lockForUpdate()->firstOrFail();
            $lots = AICreditLot::query()->where('organization_id', $order->organization_id)->where('commercial_order_id', $order->getKey())->lockForUpdate()->get();
            foreach ($lots as $lot) {
                if ($lot->source === 'conversion' && ($lot->metadata['migration_version'] ?? null) === 'estimator-paid:v1' && $cumulativeAmountMinor > 0) {
                    $holdKey = 'legacy-estimator-refund-hold:'.$order->getKey().':'.$lot->getKey().':'.$cumulativeAmountMinor;
                    if (AICreditLedgerEntry::query()->where('organization_id', $order->organization_id)->where('idempotency_key', $holdKey)->exists()) {
                        continue;
                    }
                    $reservations = AICreditReservation::query()->where('organization_id', $order->organization_id)->where('status', 'reserved')
                        ->whereHas('allocations', fn ($query) => $query->where('ai_credit_lot_id', $lot->getKey()))->lockForUpdate()->get();
                    foreach ($reservations as $reservation) {
                        $this->credits->cancel($reservation);
                    }
                    $lot->refresh();
                    $metadata = (array) $lot->metadata;
                    $metadata[AICreditLot::LEGACY_REFUND_HOLD] = true;
                    $metadata['legacy_estimator_refund_amount_minor'] = max((int) ($metadata['legacy_estimator_refund_amount_minor'] ?? 0), $cumulativeAmountMinor);
                    $lot->forceFill(['metadata' => $metadata])->save();
                    $wallet->refresh();
                    // Refund attribution to the attested remainder is unknown. Preserve its money and require financial reconciliation.
                    AICreditLedgerEntry::query()->create(['organization_id' => $order->organization_id, 'type' => 'reconciliation_hold',
                        'amount_minor' => 0, 'balance_after_minor' => $wallet->balance_minor, 'reference_type' => 'lot', 'reference_id' => $lot->getKey(),
                        'idempotency_key' => $holdKey, 'metadata' => ['commercial_order_id' => (int) $order->getKey(),
                            'cumulative_refund_amount_minor' => $cumulativeAmountMinor, 'held_units_minor' => (int) $lot->remaining_minor,
                            'reconciliation_id' => $metadata['reconciliation_id'] ?? null, 'remaining_money_proof_hash' => $metadata['remaining_money_proof_hash'] ?? null]]);

                    continue;
                }
                $key = 'refund:'.$order->public_id.':'.$lot->getKey().':'.$cumulativeAmountMinor;
                if (AICreditLedgerEntry::query()->where('organization_id', $order->organization_id)->where('idempotency_key', $key)->exists()) {
                    continue;
                }
                $metadata = (array) $lot->metadata;
                $target = min((int) $lot->original_minor, intdiv((int) $lot->original_minor * min((int) $order->amount_minor, $cumulativeAmountMinor), max(1, (int) $order->amount_minor)));
                $previous = (int) ($metadata['refunded_units_minor'] ?? 0);
                if ($target <= $previous) {
                    continue;
                }
                $reservations = AICreditReservation::query()->where('organization_id', $order->organization_id)->where('status', 'reserved')->whereHas('allocations', fn ($query) => $query->where('ai_credit_lot_id', $lot->getKey()))->lockForUpdate()->get();
                foreach ($reservations as $reservation) {
                    $this->credits->cancel($reservation);
                }
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

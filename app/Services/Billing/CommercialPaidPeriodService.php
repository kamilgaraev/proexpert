<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CommercialBillingPeriod;
use App\Models\CommercialContourChange;
use App\Models\CommercialOrder;
use App\Models\OrganizationCommercialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class CommercialPaidPeriodService
{
    public function canActivate(CommercialOrder $order, Collection $packages): bool
    {
        if (! $order->renew_subscription) {
            return true;
        }

        $current = $packages->filter(fn ($row) => in_array($row->access_source->value, ['paid_package', 'full_suite'], true)
            && in_array($row->status->value, ['active', 'scheduled_for_removal'], true))
            ->pluck('package_slug')->sort()->values()->all();
        $target = $order->selected_package_slugs;
        sort($target);

        return $current === $target;
    }

    public function prepare(CommercialOrder $order, OrganizationCommercialAccount $account, Collection $packages): void
    {
        if ($order->kind !== 'purchase') {
            return;
        }

        $term = CommercialBillingPeriod::from($order->billing_period ?? '30_days');
        $now = CarbonImmutable::instance(now());
        if ($order->renew_subscription) {
            $snapshot = [
                'start_at' => $account->current_period_start_at?->toJSON(),
                'end_at' => $account->current_period_end_at?->toJSON(),
                'billing_period' => $account->billing_period ?? '30_days',
                'offer_type' => $account->offer_type->value,
                'package_order_ids' => $packages->pluck('source_order_id', 'package_slug')->all(),
                'package_states' => $packages->mapWithKeys(fn ($row) => [$row->package_slug => $row->only([
                    'access_source', 'price_paid', 'status', 'cancel_at', 'canceled_at',
                ])])->all(),
            ];
            $start = $account->current_period_end_at !== null && $account->current_period_end_at->greaterThan($now)
                ? CarbonImmutable::instance($account->current_period_end_at)
                : $now;
            $end = $term->endAt($start);
            $order->forceFill(['paid_period_snapshot' => $snapshot, 'period_start_at' => $start, 'period_end_at' => $end])->save();
            CommercialContourChange::query()
                ->where('organization_id', $account->organization_id)
                ->where('commercial_account_id', $account->id)
                ->where('status', 'scheduled')
                ->where('apply_at', $account->current_period_end_at)
                ->update(['apply_at' => $end]);
        } elseif ($order->current_package_slugs === [] && $term !== CommercialBillingPeriod::ThirtyDays) {
            $order->forceFill(['period_start_at' => $now, 'period_end_at' => $term->endAt($now)])->save();
        }
    }

    public function restoreAfterRefund(CommercialOrder $order, OrganizationCommercialAccount $account, Collection $packages): bool
    {
        $snapshot = $order->paid_period_snapshot;
        if (! $order->renew_subscription || ! is_array($snapshot) || empty($snapshot['end_at'])) {
            return false;
        }

        $end = CarbonImmutable::parse($snapshot['end_at']);
        foreach ($packages as $row) {
            $state = $snapshot['package_states'][$row->package_slug] ?? [];
            $row->forceFill([
                'status' => $end->isFuture() ? ($state['status'] ?? 'active') : 'expired',
                'access_source' => $state['access_source'] ?? $row->access_source,
                'price_paid' => $state['price_paid'] ?? $row->price_paid,
                'current_period_start_at' => $snapshot['start_at'],
                'current_period_end_at' => $end,
                'source_order_id' => $snapshot['package_order_ids'][$row->package_slug] ?? null,
                'cancel_at' => $end->isFuture() ? ($state['cancel_at'] ?? null) : null,
                'canceled_at' => $end->isFuture() ? ($state['canceled_at'] ?? null) : now(),
            ])->save();
        }
        if ($packages->count() === count($order->selected_package_slugs)
            && $account->current_period_end_at?->equalTo($order->period_end_at)) {
            $account->forceFill([
                'status' => $end->isFuture() ? 'active' : 'suspended',
                'billing_period' => $snapshot['billing_period'],
                'offer_type' => $snapshot['offer_type'] ?? $account->offer_type,
                'current_period_start_at' => $snapshot['start_at'],
                'current_period_end_at' => $end,
                'billing_anchor_at' => $end,
                'auto_renew_enabled' => false,
                'saved_payment_method_active' => false,
            ])->save();
            CommercialContourChange::query()->where('organization_id', $account->organization_id)
                ->where('commercial_account_id', $account->id)->where('status', 'scheduled')
                ->where('apply_at', $order->period_end_at)->update(['apply_at' => $end]);
        }

        return true;
    }
}

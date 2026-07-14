<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\PaymentProviderMode;
use App\Exceptions\Billing\CommercialBillingConflictException;
use App\Models\CommercialOrder;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CommercialBillingQueryService
{
    public function __construct(
        private readonly CommercialOfferCalculator $calculator,
    ) {}

    public function quote(Organization $organization, array $input): array
    {
        $account = OrganizationCommercialAccount::query()
            ->where('organization_id', $organization->getKey())
            ->first();

        if ($account?->status->value === 'grace') {
            throw new CommercialBillingConflictException('Commercial contour cannot change during grace.');
        }

        return $this->calculator->preview(
            $input['target_package_slugs'],
            $this->currentPackageSlugs((int) $organization->getKey()),
            (bool) $input['full_suite'],
            currentPeriodStartAt: $account?->current_period_start_at,
            currentPeriodEndAt: $account?->current_period_end_at,
        );
    }

    public function order(Organization $organization, string $publicId): array
    {
        $order = CommercialOrder::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->with(['payments', 'refunds'])
            ->firstOrFail();

        return $this->orderPayload($order, false);
    }

    public function history(Organization $organization, int $perPage): LengthAwarePaginator
    {
        $paginator = CommercialOrder::query()
            ->where('organization_id', $organization->getKey())
            ->with(['payments', 'refunds'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->setCollection($paginator->getCollection()->map(
            fn (CommercialOrder $order): array => $this->orderPayload($order, true),
        ));

        return $paginator;
    }

    public function currentPackageSlugs(int $organizationId): array
    {
        $slugs = OrganizationPackageSubscription::query()
            ->where('organization_id', $organizationId)
            ->whereIn('access_source', ['paid_package', 'full_suite'])
            ->active()
            ->orderBy('package_slug')
            ->pluck('package_slug')
            ->all();

        return array_values($slugs);
    }

    private function orderPayload(CommercialOrder $order, bool $includeAttempts): array
    {
        $payments = $order->payments->sortBy('attempt_number')->values();
        $latestPayment = $payments->last();
        $refunds = $order->refunds->sortBy('created_at')->values();
        $succeededRefunds = $refunds->where('provider_status', 'succeeded');
        $refundedMinor = (int) $succeededRefunds->sum('amount_minor');
        $confirmationUsable = $order->status->value === 'pending_payment'
            && $latestPayment !== null
            && in_array($latestPayment->provider_status, ['created', 'pending', 'waiting_for_capture'], true);
        $paidPayment = $payments->last(
            static fn ($payment): bool => $payment->provider_status === 'succeeded',
        );
        $canceledPayment = $payments->last(
            static fn ($payment): bool => $payment->provider_status === 'canceled',
        );
        $status = $order->status->value === 'pending_payment'
            && $order->kind === 'renewal'
            && $latestPayment?->provider_status === 'canceled'
                ? 'failed'
                : $order->status->value;

        $payload = [
            'order_id' => $order->public_id,
            'kind' => $order->kind,
            'status' => $status,
            'payment_status' => $latestPayment?->provider_status,
            'amount' => $order->amount,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'selected_package_slugs' => $order->selected_package_slugs,
            'offer_type' => $order->offer_type->value,
            'period_start_at' => $order->period_start_at?->toJSON(),
            'period_end_at' => $order->period_end_at?->toJSON(),
            'auto_renew_consent' => $order->auto_renew_consent,
            'test_mode' => PaymentProviderMode::configured()->testMode(),
            'confirmation_url' => $confirmationUsable ? $latestPayment->confirmation_url : null,
            'created_at' => $order->created_at?->toJSON(),
            'paid_at' => in_array($order->status->value, ['paid', 'refunded'], true)
                ? ($paidPayment?->terminal_at ?? $paidPayment?->updated_at)?->toJSON()
                : null,
            'canceled_at' => $order->status->value === 'canceled'
                ? ($canceledPayment?->terminal_at ?? $canceledPayment?->updated_at)?->toJSON()
                : null,
            'refunds_summary' => [
                'count' => $succeededRefunds->count(),
                'amount' => $this->money($refundedMinor),
                'amount_minor' => $refundedMinor,
                'currency' => $order->currency,
                'fully_refunded' => $refundedMinor >= $order->amount_minor,
            ],
        ];

        if ($includeAttempts) {
            $payload['payments'] = $payments->map(static fn ($payment): array => [
                'attempt_number' => $payment->attempt_number,
                'role' => $payment->role,
                'provider' => $payment->provider,
                'status' => $payment->provider_status,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'attempted_at' => $payment->attempted_at?->toJSON(),
                'terminal_at' => $payment->terminal_at?->toJSON(),
                'created_at' => $payment->created_at?->toJSON(),
            ])->all();
            $payload['refunds'] = $refunds->map(static fn ($refund): array => [
                'status' => $refund->provider_status,
                'amount_minor' => $refund->amount_minor,
                'currency' => $refund->currency,
                'created_at' => $refund->created_at?->toJSON(),
            ])->all();
        }

        return $payload;
    }

    private function money(int $amountMinor): string
    {
        return sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
    }
}

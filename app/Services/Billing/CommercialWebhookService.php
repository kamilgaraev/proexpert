<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Enums\Billing\CommercialOfferType;
use App\Enums\Billing\PaymentProviderMode;
use App\Exceptions\Billing\RetryableCommercialWebhookException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Models\CommercialRenewalCycle;
use App\Models\CommercialWebhookEvent;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use App\Services\Contractor\ContractorReferralRewardService;
use App\Services\Modules\PackageCatalogService;

use function trans_message;

final class CommercialWebhookService implements CommercialWebhookProcessor
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PackageCatalogService $catalog,
        private readonly CommercialWebhookTransactionRunner $transactions,
        private readonly ContractorReferralRewardService $referralRewards,
    ) {}

    public function process(YooKassaWebhookNotification $notification, string $sourceIp): string
    {
        if ($notification->event === 'payment_method.active') {
            return $this->recordNoOp($notification, $sourceIp);
        }

        if ($notification->event === 'refund.succeeded') {
            $refund = $this->gateway->getRefund($notification->objectId);
            $payment = $this->gateway->getPayment($refund->paymentId);

            return $this->processAuthoritativeRefund($notification, $sourceIp, $refund, $payment);
        }

        $payment = $this->gateway->getPayment($notification->objectId);

        return $this->processAuthoritativePayment($notification, $sourceIp, $payment);
    }

    public function processAuthoritativePayment(
        YooKassaWebhookNotification $notification,
        string $sourceIp,
        PaymentGatewayResult $authoritative,
    ): string {
        $fingerprint = $this->fingerprint($notification, $authoritative->status);

        return $this->transactions->run($fingerprint, function () use (
            $notification,
            $sourceIp,
            $authoritative,
            $fingerprint,
        ): string {
            if ($this->isDuplicate($fingerprint)) {
                return 'duplicate';
            }

            $payment = $this->resolvePaymentForUpdate($notification, $authoritative);

            $order = CommercialOrder::query()->whereKey($payment->commercial_order_id)->lockForUpdate()->firstOrFail();
            $account = OrganizationCommercialAccount::query()
                ->whereKey($order->commercial_account_id)
                ->where('organization_id', $order->organization_id)
                ->lockForUpdate()
                ->firstOrFail();
            $packageRows = OrganizationPackageSubscription::query()
                ->where('organization_id', $order->organization_id)
                ->lockForUpdate()
                ->get();

            if (! $this->matchesOrder($authoritative, $payment, $order)) {
                return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'mismatch');
            }

            $authoritativeEvent = match ($authoritative->status) {
                'succeeded' => 'payment.succeeded',
                'waiting_for_capture' => 'payment.waiting_for_capture',
                'canceled' => 'payment.canceled',
                default => 'payment.status_updated',
            };

            if ($authoritativeEvent === 'payment.succeeded') {
                if (! $authoritative->paid) {
                    return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'mismatch');
                }

                if (in_array($order->status->value, ['paid', 'refunded'], true)) {
                    return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'stale');
                }

                if ($order->kind === 'purchase' && $account->status->value === 'grace') {
                    $payment->forceFill([
                        'provider_status' => 'succeeded',
                        'confirmation_url' => null,
                        'payment_method_id' => null,
                        'payment_method_saved' => false,
                        'safe_response' => $authoritative->safeResponse,
                        'refunded_amount_minor' => $authoritative->refundedAmountMinor,
                        'terminal_at' => now(),
                    ])->save();

                    return $this->record(
                        $notification,
                        $sourceIp,
                        $fingerprint,
                        $authoritative->status,
                        'manual_review',
                    );
                }

                $cycle = $order->kind === 'renewal' ? CommercialRenewalCycle::query()->where('commercial_order_id', $order->id)->lockForUpdate()->first() : null;
                if ($cycle !== null && (in_array($cycle->status, ['suspended', 'manual_review'], true) || now()->greaterThanOrEqualTo($cycle->grace_deadline_at))) {
                    $cycle->forceFill(['status' => 'manual_review', 'manual_review_at' => now()])->save();

                    return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'manual_review');
                }
                $this->activate($order, $payment, $account, $packageRows, $authoritative);
                if ($order->kind === 'purchase') {
                    $order->refresh();
                    $payment->refresh();
                    $this->referralRewards->handleFirstPaidOrder($order, $payment);
                }
                if ($cycle !== null) {
                    $cycle->forceFill(['status' => 'paid', 'paid_at' => now(), 'next_attempt_at' => null])->save();
                }
                $this->notify($order, 'commercial_payment_succeeded', 'billing.webhook.payment_succeeded');

                return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'processed');
            }

            if (in_array($order->status->value, ['paid', 'refunded'], true)) {
                return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'stale');
            }

            $payment->forceFill([
                'provider_status' => $authoritative->status,
                'safe_response' => $authoritative->safeResponse,
                'refunded_amount_minor' => $authoritative->refundedAmountMinor,
                'last_reconciled_at' => now(),
                'reconciliation_required' => ! in_array($authoritative->status, ['succeeded', 'canceled'], true),
            ])->save();

            if ($authoritativeEvent === 'payment.canceled'
                && $order->status->value === 'pending_payment') {
                if ($order->kind === 'renewal') {
                    $wasInGrace = $account->status->value === 'grace';
                    $reason = $authoritative->cancellationReason ?? 'unknown';
                    $category = $reason === 'permission_revoked' ? 'method_revoked' : (in_array($reason, ['insufficient_funds', 'issuer_unavailable', 'internal_timeout', 'general_decline', 'payment_method_limit_exceeded'], true) ? 'retryable' : 'non_retryable');
                    $retryable = $category === 'retryable';
                    $payment->forceFill(['terminal_failure_reason' => $reason, 'failure_category' => $category, 'terminal_at' => now()])->save();
                    $cycle = CommercialRenewalCycle::query()->where('commercial_order_id', $order->id)->lockForUpdate()->first();
                    if ($cycle !== null) {
                        $cycle->forceFill(['status' => $retryable ? 'grace' : 'disabled', 'next_attempt_at' => $retryable ? now()->addDay()->startOfDay()->addHours(3) : null])->save();
                    }
                    $account->forceFill(['status' => 'grace', 'grace_started_at' => $cycle?->due_at, 'grace_ends_at' => $cycle?->grace_deadline_at, 'auto_renew_enabled' => $retryable && $account->auto_renew_enabled, 'saved_payment_method_active' => $retryable])->save();
                    foreach ($packageRows as $row) {
                        if (in_array($row->access_source->value, ['paid_package', 'full_suite', 'corporate'], true)) {
                            if (in_array($row->package_slug, $order->selected_package_slugs, true)) {
                                $row->forceFill(['status' => 'grace'])->save();

                                continue;
                            }
                            if ($row->access_source->value === 'corporate') {
                                continue;
                            }
                            $row->forceFill([
                                'status' => 'expired',
                                'current_period_end_at' => $order->period_start_at,
                                'cancel_at' => null,
                                'canceled_at' => $order->period_start_at,
                            ])->save();
                        }
                    }
                    if (! $wasInGrace) {
                        $this->notify($order, 'commercial_grace_started_'.$order->public_id, 'billing.renewal.grace_started');
                    }
                    if (! $retryable) {
                        $this->notify($order, 'commercial_method_disabled_'.$order->public_id, 'billing.renewal.method_disabled');
                    }
                } else {
                    $order->forceFill(['status' => 'canceled'])->save();
                }
                $this->notify($order, 'commercial_payment_canceled', 'billing.webhook.payment_canceled');
            }

            return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'processed');
        });
    }

    public function processAuthoritativeRefund(
        YooKassaWebhookNotification $notification,
        string $sourceIp,
        RefundGatewayResult $refund,
        PaymentGatewayResult $authoritativePayment,
    ): string {
        $fingerprint = $this->fingerprint($notification, $refund->status);

        return $this->transactions->run($fingerprint, function () use (
            $notification,
            $sourceIp,
            $refund,
            $authoritativePayment,
            $fingerprint,
        ): string {
            if ($this->isDuplicate($fingerprint)) {
                return 'duplicate';
            }

            $payment = CommercialPayment::query()
                ->where('provider', 'yookassa')
                ->where('provider_payment_id', $refund->paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return $this->record($notification, $sourceIp, $fingerprint, $refund->status, 'no_op');
            }

            $order = CommercialOrder::query()->whereKey($payment->commercial_order_id)->lockForUpdate()->firstOrFail();
            OrganizationCommercialAccount::query()
                ->whereKey($order->commercial_account_id)
                ->where('organization_id', $order->organization_id)
                ->lockForUpdate()
                ->firstOrFail();
            $sourceRows = OrganizationPackageSubscription::query()
                ->where('organization_id', $order->organization_id)
                ->where('source_order_id', $order->id)
                ->lockForUpdate()
                ->get();

            $expectedStatus = match ($notification->event) {
                'refund.succeeded' => 'succeeded',
                'refund.reconciliation' => $refund->status,
                default => '',
            };
            $valid = $refund->id === $notification->objectId
                && $refund->status === $expectedStatus
                && $refund->currency === $payment->currency
                && $refund->amountMinor > 0
                && $authoritativePayment->id === $payment->provider_payment_id
                && $authoritativePayment->status === 'succeeded'
                && $authoritativePayment->paid
                && $this->matchesOrder($authoritativePayment, $payment, $order);

            if (! $valid) {
                return $this->record($notification, $sourceIp, $fingerprint, $refund->status, 'mismatch');
            }

            $refundRecord = CommercialRefund::query()
                ->where('provider_refund_id', $refund->id)
                ->lockForUpdate()
                ->first();
            $correlationKey = trim((string) ($refund->metadata['refund_idempotency_key'] ?? ''));
            if ($refundRecord === null && $correlationKey !== '') {
                $refundRecord = CommercialRefund::query()
                    ->where('provider_idempotency_key', $correlationKey)
                    ->lockForUpdate()
                    ->first();
            }
            if ($refundRecord !== null
                && ($refundRecord->commercial_order_id !== $order->id
                    || $refundRecord->commercial_payment_id !== $payment->id
                    || $refundRecord->amount_minor !== $refund->amountMinor
                    || $refundRecord->currency !== $refund->currency)) {
                return $this->record($notification, $sourceIp, $fingerprint, $refund->status, 'mismatch');
            }
            if ($refundRecord === null) {
                $refundRecord = CommercialRefund::query()->create([
                    'commercial_order_id' => $order->id,
                    'commercial_payment_id' => $payment->id,
                    'provider' => 'yookassa',
                    'provider_refund_id' => $refund->id,
                    'provider_idempotency_key' => substr('webhook:'.$refund->id, 0, 64),
                    'request_fingerprint' => hash('sha256', implode('|', [$order->id, $payment->id, $refund->id])),
                    'provider_status' => $refund->status,
                    'amount_minor' => $refund->amountMinor,
                    'currency' => $refund->currency,
                    'safe_response' => $refund->safeResponse,
                    'reconciliation_required' => ! in_array($refund->status, ['succeeded', 'canceled'], true),
                    'last_reconciled_at' => now(),
                ]);
            } else {
                $refundRecord->forceFill([
                    'provider_refund_id' => $refund->id,
                    'provider_status' => $refund->status,
                    'safe_response' => $refund->safeResponse,
                    'reconciliation_required' => ! in_array($refund->status, ['succeeded', 'canceled'], true),
                    'last_reconciled_at' => now(),
                ])->save();
            }
            if ($refund->status !== 'succeeded') {
                return $this->record($notification, $sourceIp, $fingerprint, $refund->status, 'processed');
            }
            $previousRefundedAmount = $payment->refunded_amount_minor;
            $effectiveRefundedAmount = max(
                $previousRefundedAmount,
                $authoritativePayment->refundedAmountMinor,
            );
            $paymentUpdate = ['refunded_amount_minor' => $effectiveRefundedAmount];

            if ($authoritativePayment->refundedAmountMinor > $previousRefundedAmount) {
                $paymentUpdate['safe_response'] = $authoritativePayment->safeResponse;
            }

            $payment->forceFill($paymentUpdate)->save();

            if ($authoritativePayment->refundedAmountMinor <= $previousRefundedAmount) {
                return $this->record($notification, $sourceIp, $fingerprint, $refund->status, 'stale_refund');
            }

            $full = $previousRefundedAmount < $payment->amount_minor
                && $effectiveRefundedAmount >= $payment->amount_minor;

            if ($full) {
                $order->forceFill(['status' => 'refunded'])->save();

                foreach ($sourceRows as $row) {
                    $row->forceFill([
                        'status' => 'expired',
                        'current_period_end_at' => now(),
                        'cancel_at' => null,
                        'canceled_at' => now(),
                    ])->save();
                }
            }

            $this->notify(
                $order,
                $full ? 'commercial_refund_full' : 'commercial_refund_partial',
                $full ? 'billing.webhook.refund_full' : 'billing.webhook.refund_partial',
            );
            $result = $full ? 'full_refund' : 'partial_refund';

            return $this->record($notification, $sourceIp, $fingerprint, $refund->status, $result);
        });
    }

    private function activate(
        CommercialOrder $order,
        CommercialPayment $payment,
        OrganizationCommercialAccount $account,
        $packageRows,
        PaymentGatewayResult $authoritative,
    ): void {
        $order->forceFill(['status' => 'paid'])->save();
        $renewal = $order->kind === 'renewal';
        $canRenew = $renewal ? $account->auto_renew_enabled : ($order->auto_renew_consent
            && $authoritative->paymentMethodSaved
            && trim((string) $authoritative->paymentMethodId) !== '');
        $payment->forceFill([
            'provider_status' => 'succeeded',
            'confirmation_url' => null,
            'payment_method_id' => $renewal ? null : ($canRenew ? $authoritative->paymentMethodId : null),
            'payment_method_saved' => ! $renewal && $canRenew,
            'safe_response' => $authoritative->safeResponse,
            'refunded_amount_minor' => $authoritative->refundedAmountMinor,
            'last_reconciled_at' => now(),
            'reconciliation_required' => false,
        ])->save();
        $account->forceFill([
            'status' => 'active',
            'offer_type' => $order->offer_type->value,
            'quote_version' => $order->quote_version,
            'current_period_start_at' => $order->period_start_at,
            'current_period_end_at' => $order->period_end_at,
            'billing_anchor_at' => $order->period_end_at,
            'auto_renew_enabled' => $canRenew,
            'saved_payment_method_id' => $renewal ? $account->saved_payment_method_id : ($canRenew ? $authoritative->paymentMethodId : null),
            'saved_payment_method_active' => $canRenew,
            'saved_payment_method_at' => $renewal ? $account->saved_payment_method_at : ($canRenew ? now() : null),
            'auto_renew_consented_at' => $renewal ? $account->auto_renew_consented_at : ($canRenew ? now() : null),
            'auto_renew_terms_version' => $renewal ? $account->auto_renew_terms_version : ($canRenew ? (string) config('commercial_offers.auto_renew_terms_version', '1') : null),
            'grace_started_at' => null,
            'grace_ends_at' => null,
        ])->save();

        $contour = $renewal
            ? $order->selected_package_slugs
            : ($order->offer_type === CommercialOfferType::FullSuite
                ? array_column($this->catalog->allPackages(), 'slug')
                : $order->selected_package_slugs);
        $source = $order->offer_type === CommercialOfferType::FullSuite ? 'full_suite' : 'paid_package';

        foreach ($packageRows as $row) {
            if (in_array($row->package_slug, $contour, true)) {
                continue;
            }

            if (in_array($row->access_source->value, ['paid_package', 'full_suite'], true)) {
                $row->forceFill([
                    'status' => 'expired',
                    'current_period_end_at' => now(),
                    'cancel_at' => null,
                    'canceled_at' => now(),
                ])->save();
            }
        }

        foreach ($contour as $slug) {
            $row = $packageRows->firstWhere('package_slug', $slug)
                ?? new OrganizationPackageSubscription([
                    'organization_id' => $order->organization_id,
                    'package_slug' => $slug,
                ]);
            $row->forceFill([
                'commercial_account_id' => $account->id,
                'status' => 'active',
                'access_source' => $source,
                'price_paid' => $order->amount,
                'current_period_start_at' => $order->period_start_at,
                'current_period_end_at' => $order->period_end_at,
                'trial_started_at' => null,
                'trial_ends_at' => null,
                'cancel_at' => null,
                'canceled_at' => null,
                'source_order_id' => $order->id,
            ])->save();
        }
    }

    private function matchesOrder(
        PaymentGatewayResult $authoritative,
        CommercialPayment $payment,
        CommercialOrder $order,
    ): bool {
        return $authoritative->id === $payment->provider_payment_id
            && $this->matchesOrderIdentity($authoritative, $payment, $order);
    }

    private function matchesOrderIdentity(
        PaymentGatewayResult $authoritative,
        CommercialPayment $payment,
        CommercialOrder $order,
    ): bool {
        return $payment->provider === 'yookassa'
            && $authoritative->amountMinor === $payment->amount_minor
            && $authoritative->amountMinor === $order->amount_minor
            && strtoupper($authoritative->currency) === strtoupper($payment->currency)
            && strtoupper($authoritative->currency) === strtoupper($order->currency)
            && (string) ($authoritative->metadata['order_id'] ?? '') === $order->public_id
            && (string) ($authoritative->metadata['organization_id'] ?? '') === (string) $order->organization_id
            && $authoritative->test === PaymentProviderMode::configured()->testMode();
    }

    private function resolvePaymentForUpdate(
        YooKassaWebhookNotification $notification,
        PaymentGatewayResult $authoritative,
    ): CommercialPayment {
        $payment = CommercialPayment::query()
            ->where('provider', 'yookassa')
            ->where('provider_payment_id', $notification->objectId)
            ->lockForUpdate()
            ->first();

        if ($payment !== null) {
            return $payment;
        }

        $publicOrderId = trim((string) ($authoritative->metadata['order_id'] ?? ''));
        $organizationId = filter_var(
            $authoritative->metadata['organization_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($publicOrderId === '' || $organizationId === false) {
            throw new RetryableCommercialWebhookException('Commercial payment is not locally bindable yet.');
        }

        $order = CommercialOrder::query()
            ->where('public_id', $publicOrderId)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending_payment')
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            throw new RetryableCommercialWebhookException('Commercial payment is not locally bindable yet.');
        }

        $cycle = $order->kind === 'renewal'
            ? CommercialRenewalCycle::query()
                ->where('commercial_order_id', $order->id)
                ->where('commercial_account_id', $order->commercial_account_id)
                ->where('organization_id', $order->organization_id)
                ->lockForUpdate()
                ->first()
            : null;
        if ($order->kind === 'renewal' && $cycle === null) {
            throw new RetryableCommercialWebhookException('Commercial payment is not locally bindable yet.');
        }

        $payments = CommercialPayment::query()
            ->where('commercial_order_id', $order->id)
            ->where('provider', 'yookassa')
            ->whereNull('provider_payment_id')
            ->when(
                $order->kind === 'renewal',
                fn ($query) => $query->where('role', 'renewal')->where('commercial_renewal_cycle_id', $cycle?->id),
                fn ($query) => $query->where('role', 'initial')->whereNull('commercial_renewal_cycle_id'),
            )
            ->lockForUpdate()
            ->get();

        $manualPaymentKey = trim((string) ($authoritative->metadata['manual_payment_key'] ?? ''));
        if ($manualPaymentKey !== '') {
            $payments = $payments->where('provider_idempotency_key', $manualPaymentKey)->values();
        }

        $payment = $payments->count() === 1 ? $payments->first() : null;

        if ($payment === null
            || $authoritative->id !== $notification->objectId
            || ! $this->matchesOrderIdentity($authoritative, $payment, $order)) {
            throw new RetryableCommercialWebhookException('Commercial payment is not locally bindable yet.');
        }

        $payment->forceFill(['provider_payment_id' => $authoritative->id])->save();

        return $payment;
    }

    private function recordNoOp(YooKassaWebhookNotification $notification, string $sourceIp): string
    {
        $fingerprint = $this->fingerprint($notification, $notification->objectState);

        return $this->transactions->run($fingerprint, function () use ($notification, $sourceIp, $fingerprint): string {
            if ($this->isDuplicate($fingerprint)) {
                return 'duplicate';
            }

            return $this->record($notification, $sourceIp, $fingerprint, $notification->objectState, 'no_op');
        });
    }

    private function record(
        YooKassaWebhookNotification $notification,
        string $sourceIp,
        string $fingerprint,
        string $authoritativeStatus,
        string $result,
    ): string {
        CommercialWebhookEvent::query()->create([
            'provider' => 'yookassa',
            'event_name' => $notification->event,
            'object_id' => $notification->objectId,
            'authoritative_status' => $authoritativeStatus,
            'processing_result' => $result,
            'source_ip' => $sourceIp,
            'fingerprint' => $fingerprint,
            'safe_payload' => $notification->safePayload,
            'processed_at' => now(),
        ]);

        return $result;
    }

    private function notify(CommercialOrder $order, string $type, string $messageKey): void
    {
        Notification::query()->create([
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $order->user_id,
            'organization_id' => $order->organization_id,
            'notification_type' => 'billing',
            'priority' => 'normal',
            'channels' => ['in_app'],
            'delivery_status' => [],
            'data' => [
                'title' => trans_message('billing.webhook.title'),
                'message' => trans_message($messageKey),
                'order_id' => $order->public_id,
            ],
        ]);
    }

    private function fingerprint(YooKassaWebhookNotification $notification, string $status): string
    {
        $runId = str_ends_with($notification->event, '.reconciliation')
            ? (string) ($notification->safePayload['run_id'] ?? '')
            : '';

        return hash('sha256', implode('|', ['yookassa', $notification->event, $notification->objectId, $status, $runId]));
    }

    private function isDuplicate(string $fingerprint): bool
    {
        return CommercialWebhookEvent::query()->where('fingerprint', $fingerprint)->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Enums\Billing\CommercialOfferType;
use App\Exceptions\Billing\RetryableCommercialWebhookException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Models\CommercialWebhookEvent;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;

use function trans_message;

final class CommercialWebhookService implements CommercialWebhookProcessor
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PackageCatalogService $catalog,
        private readonly CommercialWebhookTransactionRunner $transactions,
    ) {}

    public function process(YooKassaWebhookNotification $notification, string $sourceIp): string
    {
        if ($notification->event === 'payment_method.active') {
            return $this->recordNoOp($notification, $sourceIp);
        }

        if ($notification->event === 'refund.succeeded') {
            $refund = $this->gateway->getRefund($notification->objectId);
            $payment = $this->gateway->getPayment($refund->paymentId);

            return $this->processRefund($notification, $sourceIp, $refund, $payment);
        }

        $payment = $this->gateway->getPayment($notification->objectId);

        return $this->processPayment($notification, $sourceIp, $payment);
    }

    private function processPayment(
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

            $expectedStatus = match ($notification->event) {
                'payment.succeeded' => 'succeeded',
                'payment.waiting_for_capture' => 'waiting_for_capture',
                'payment.canceled' => 'canceled',
                default => '',
            };

            if ($authoritative->status !== $expectedStatus) {
                return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'stale');
            }

            if ($notification->event === 'payment.succeeded') {
                if (! $authoritative->paid) {
                    return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'mismatch');
                }

                if (in_array($order->status->value, ['paid', 'refunded'], true)) {
                    return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'stale');
                }

                $this->activate($order, $payment, $account, $packageRows, $authoritative);
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
            ])->save();

            if ($notification->event === 'payment.canceled'
                && $order->status->value === 'pending_payment') {
                $order->forceFill(['status' => 'canceled'])->save();
                $this->notify($order, 'commercial_payment_canceled', 'billing.webhook.payment_canceled');
            }

            return $this->record($notification, $sourceIp, $fingerprint, $authoritative->status, 'processed');
        });
    }

    private function processRefund(
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

            $valid = $refund->id === $notification->objectId
                && $refund->status === 'succeeded'
                && $refund->currency === $payment->currency
                && $refund->amountMinor > 0
                && $authoritativePayment->id === $payment->provider_payment_id
                && $authoritativePayment->status === 'succeeded'
                && $authoritativePayment->paid
                && $this->matchesOrder($authoritativePayment, $payment, $order);

            if (! $valid) {
                return $this->record($notification, $sourceIp, $fingerprint, $refund->status, 'mismatch');
            }

            CommercialRefund::query()->create([
                'commercial_order_id' => $order->id,
                'commercial_payment_id' => $payment->id,
                'provider' => 'yookassa',
                'provider_refund_id' => $refund->id,
                'provider_status' => $refund->status,
                'amount_minor' => $refund->amountMinor,
                'currency' => $refund->currency,
                'safe_response' => $refund->safeResponse,
            ]);
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
        $canRenew = $order->auto_renew_consent
            && $authoritative->paymentMethodSaved
            && trim((string) $authoritative->paymentMethodId) !== '';
        $payment->forceFill([
            'provider_status' => 'succeeded',
            'confirmation_url' => null,
            'payment_method_id' => $canRenew ? $authoritative->paymentMethodId : null,
            'payment_method_saved' => $canRenew,
            'safe_response' => $authoritative->safeResponse,
            'refunded_amount_minor' => $authoritative->refundedAmountMinor,
        ])->save();
        $account->forceFill([
            'status' => 'active',
            'offer_type' => $order->offer_type->value,
            'quote_version' => $order->quote_version,
            'current_period_start_at' => $order->period_start_at,
            'current_period_end_at' => $order->period_end_at,
            'billing_anchor_at' => $order->period_end_at,
            'auto_renew_enabled' => $canRenew,
        ])->save();

        $contour = $order->offer_type === CommercialOfferType::FullSuite
            ? array_column($this->catalog->allPackages(), 'slug')
            : $order->selected_package_slugs;
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
            && $authoritative->test === ((string) config('services.yookassa.mode', 'test') === 'test');
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

        $payment = CommercialPayment::query()
            ->where('commercial_order_id', $order->id)
            ->where('provider', 'yookassa')
            ->lockForUpdate()
            ->first();

        if ($payment === null
            || $payment->provider_payment_id !== null
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
        return hash('sha256', implode('|', ['yookassa', $notification->event, $notification->objectId, $status]));
    }

    private function isDuplicate(string $fingerprint): bool
    {
        return CommercialWebhookEvent::query()->where('fingerprint', $fingerprint)->exists();
    }
}

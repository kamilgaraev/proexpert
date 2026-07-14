<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreateRefundData;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class CommercialRefundService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly CommercialPaymentProviderPolicy $providerPolicy,
    ) {}

    public function create(
        string $orderPublicId,
        ?int $amountMinor,
        string $currency,
        string $reason,
        string $idempotenceKey,
    ): CommercialRefund {
        $currency = strtoupper(trim($currency));
        $reason = trim($reason);
        $idempotenceKey = trim($idempotenceKey);
        if ($orderPublicId === '' || $reason === '' || strlen($reason) > 250
            || $idempotenceKey === '' || strlen($idempotenceKey) > 64
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || ($amountMinor !== null && $amountMinor <= 0)) {
            throw new DomainException(trans_message('billing.refund.invalid'));
        }

        $organizationId = (int) CommercialOrder::query()
            ->where('public_id', $orderPublicId)
            ->value('organization_id');
        if ($organizationId <= 0) {
            throw new DomainException(trans_message('billing.refund.invalid'));
        }
        $this->providerPolicy->assertCanCreateRefund($organizationId);

        [$refund, $payment] = $this->prepare(
            $orderPublicId,
            $amountMinor,
            $currency,
            $reason,
            $idempotenceKey,
        );
        if ($refund->provider_refund_id !== null) {
            return $refund;
        }

        $request = new CreateRefundData(
            idempotenceKey: $refund->provider_idempotency_key,
            paymentId: (string) $payment->provider_payment_id,
            amountMinor: $refund->amount_minor,
            currency: $refund->currency,
            description: trans_message('billing.refund.provider_description'),
            metadata: [
                'order_id' => $refund->order->public_id,
                'organization_id' => $refund->order->organization_id,
                'refund_idempotency_key' => $refund->provider_idempotency_key,
            ],
        );
        $result = $this->gateway->createRefund($request);
        $matchesRequest = $result->paymentId === $request->paymentId
            && $result->amountMinor === $request->amountMinor
            && strtoupper($result->currency) === strtoupper($request->currency)
            && in_array($result->status, ['pending', 'succeeded', 'canceled'], true)
            && (string) ($result->metadata['refund_idempotency_key'] ?? '') === $request->idempotenceKey;

        $bound = DB::transaction(function () use ($refund, $result, $matchesRequest): bool {
            $current = CommercialRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($current->provider_refund_id !== null && $current->provider_refund_id !== $result->id) {
                throw new DomainException(trans_message('billing.refund.conflict'));
            }
            if ($current->provider_refund_id === $result->id) {
                return true;
            }
            $current->forceFill([
                'provider_refund_id' => $result->id,
                'provider_status' => $matchesRequest ? $result->status : 'unknown',
                'safe_response' => $result->safeResponse,
                'reconciliation_required' => true,
            ])->save();

            return $matchesRequest;
        }, 3);
        if (! $bound) {
            throw new DomainException(trans_message('billing.refund.conflict'));
        }

        return $refund->fresh();
    }

    private function prepare(
        string $orderPublicId,
        ?int $amountMinor,
        string $currency,
        string $reason,
        string $idempotenceKey,
    ): array {
        $fingerprint = hash('sha256', implode('|', [$orderPublicId, $amountMinor ?? 'full', $currency, $reason]));

        try {
            return DB::transaction(function () use ($orderPublicId, $amountMinor, $currency, $idempotenceKey, $fingerprint): array {
                $existing = CommercialRefund::query()->where('provider_idempotency_key', $idempotenceKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                        throw new DomainException(trans_message('billing.refund.conflict'));
                    }

                    return [$existing->load('order'), $existing->payment];
                }

                $order = CommercialOrder::query()->where('public_id', $orderPublicId)->lockForUpdate()->firstOrFail();
                if ($order->status->value !== 'paid' || $order->currency !== $currency) {
                    throw new DomainException(trans_message('billing.refund.invalid'));
                }
                $payment = CommercialPayment::query()
                    ->where('commercial_order_id', $order->id)
                    ->where('provider_status', 'succeeded')
                    ->whereNotNull('provider_payment_id')
                    ->orderByDesc('attempt_number')
                    ->lockForUpdate()
                    ->first();
                if ($payment === null || $payment->currency !== $currency || $payment->amount_minor !== $order->amount_minor) {
                    throw new DomainException(trans_message('billing.refund.invalid'));
                }
                $reserved = (int) CommercialRefund::query()
                    ->where('commercial_order_id', $order->id)
                    ->where('commercial_payment_id', $payment->id)
                    ->where('provider_status', '!=', 'canceled')
                    ->sum('amount_minor');
                $remainder = $payment->amount_minor - $reserved;
                $requested = $amountMinor ?? $remainder;
                if ($requested <= 0 || $requested > $remainder) {
                    throw new DomainException(trans_message('billing.refund.invalid'));
                }
                $refund = CommercialRefund::query()->create([
                    'commercial_order_id' => $order->id,
                    'commercial_payment_id' => $payment->id,
                    'provider' => 'yookassa',
                    'provider_refund_id' => null,
                    'provider_idempotency_key' => $idempotenceKey,
                    'request_fingerprint' => $fingerprint,
                    'provider_status' => 'created',
                    'amount_minor' => $requested,
                    'currency' => $currency,
                    'reconciliation_required' => true,
                ]);

                return [$refund->setRelation('order', $order), $payment];
            }, 3);
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            $existing = CommercialRefund::query()->where('provider_idempotency_key', $idempotenceKey)->first();
            if ($existing === null || ! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw new DomainException(trans_message('billing.refund.conflict'), previous: $exception);
            }

            return [$existing->load('order'), $existing->payment];
        }
    }
}

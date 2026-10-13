<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\LegacyPaidEstimateConversionEvidence;
use App\Enums\Billing\CommercialOrderStatus;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\OrganizationResourceAllocation;
use DomainException;

/** Purchase evidence proves the paid ceiling; the remaining value still needs a financial attestation. */
final readonly class LegacyEstimatorPurchaseEvidence
{
    /** @param array<string,mixed> $snapshot */
    private function __construct(public CommercialOrder $order, public array $snapshot, public int $paidAmountMinor) {}

    public static function capture(OrganizationResourceAllocation $allocation, bool $forUpdate = false): self
    {
        $metadata = $allocation->metadata ?? [];
        $publicId = $metadata['commercial_order_id'] ?? null;
        $amount = $metadata['amount_minor'] ?? null;
        if ($allocation->source !== 'paid_addon' || $allocation->limit_key !== 'ai_estimates_month'
            || ! is_string($publicId) || ! is_int($amount) || $amount < 1 || $amount > 1_000_000_000_000
            || ($metadata['currency'] ?? null) !== 'RUB') {
            throw new DomainException('legacy_estimator_purchase_evidence_missing');
        }
        $query = CommercialOrder::query()->where('organization_id', $allocation->organization_id)->where('public_id', $publicId);
        $order = ($forUpdate ? $query->lockForUpdate() : $query)->first();
        if ($order === null || $order->status !== CommercialOrderStatus::Paid || $order->currency !== 'RUB'
            || (int) $order->amount_minor < $amount
            || ($allocation->commercial_account_id !== null && (int) $order->commercial_account_id !== (int) $allocation->commercial_account_id)) {
            throw new DomainException('legacy_estimator_purchase_evidence_missing');
        }
        $matching = array_values(array_filter($order->selected_resource_addons ?? [], static fn (mixed $item): bool => is_array($item) && ($item['slug'] ?? null) === $allocation->resource_slug
            && ($item['limit_key'] ?? null) === 'ai_estimates_month' && ($item['amount_minor'] ?? null) === $amount
            && ($item['currency'] ?? $order->currency) === 'RUB'
            && is_numeric($item['quantity'] ?? null) && \Brick\Math\BigDecimal::of((string) $item['quantity'])->compareTo($allocation->quantity) === 0));
        if (count($matching) !== 1) {
            throw new DomainException('legacy_estimator_purchase_evidence_missing');
        }
        $paymentsQuery = CommercialPayment::query()->where('commercial_order_id', $order->id)->orderBy('id');
        $payments = ($forUpdate ? $paymentsQuery->lockForUpdate() : $paymentsQuery)->get();
        $paid = $payments->filter(static fn (CommercialPayment $payment): bool => $payment->provider_status === 'succeeded');
        if ($paid->count() !== 1 || $paid->first()->currency !== 'RUB'
            || (int) $paid->first()->amount_minor !== (int) $order->amount_minor
            || (int) $paid->first()->refunded_amount_minor !== 0 || $paid->first()->reconciliation_required
            || $payments->contains(static fn (CommercialPayment $payment): bool => ! in_array($payment->provider_status, ['succeeded', 'canceled', 'failed'], true))) {
            throw new DomainException('legacy_estimator_purchase_requires_reconciliation');
        }
        $refundQuery = $order->refunds()->orderBy('id');
        $refunds = ($forUpdate ? $refundQuery->lockForUpdate() : $refundQuery)->get();
        if ($refunds->isNotEmpty()) {
            throw new DomainException('legacy_estimator_refund_requires_reconciliation');
        }
        $snapshot = ['source' => 'confirmed_commercial_purchase', 'organization_id' => (int) $allocation->organization_id,
            'allocation_hash' => LegacyPaidEstimateConversionEvidence::allocationHash($allocation),
            'commercial_order_id' => (int) $order->id, 'order_public_id' => $order->public_id, 'order_status' => $order->status->value,
            'order_amount_minor' => (int) $order->amount_minor, 'order_quote_version' => (int) $order->quote_version, 'currency' => 'RUB',
            'allocation_paid_amount_minor' => $amount,
            'payments' => $payments->map(static fn (CommercialPayment $payment): array => ['id' => (int) $payment->id,
                'status' => $payment->provider_status, 'amount_minor' => (int) $payment->amount_minor,
                'refunded_amount_minor' => (int) $payment->refunded_amount_minor, 'currency' => $payment->currency,
                'reconciliation_required' => (bool) $payment->reconciliation_required])->all()];

        return new self($order, $snapshot, $amount);
    }

    public function fingerprint(): string
    {
        return hash('sha256', CanonicalPipelineJson::encode($this->snapshot));
    }
}

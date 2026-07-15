<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CommercialReconciliationService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly CommercialWebhookProcessor $webhookProcessor,
    ) {}

    public function run(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $refundLimit = max(1, intdiv($limit, 2));
        $paymentLimit = max(0, $limit - $refundLimit);
        $paymentIds = $paymentLimit === 0 ? collect() : CommercialPayment::query()
            ->whereNotNull('provider_payment_id')
            ->where(function ($query): void {
                $query->whereIn('provider_status', ['pending', 'waiting_for_capture', 'unknown'])
                    ->orWhere('reconciliation_required', true);
            })
            ->orderByRaw('COALESCE(last_reconciled_at, created_at)')
            ->orderBy('id')
            ->limit($paymentLimit)
            ->pluck('id');
        $refundIds = CommercialRefund::query()
            ->whereNotNull('provider_refund_id')
            ->where(function ($query): void {
                $query->whereIn('provider_status', ['created', 'pending', 'unknown'])
                    ->orWhere('reconciliation_required', true);
            })
            ->orderByRaw('COALESCE(last_reconciled_at, created_at)')
            ->orderBy('id')
            ->limit($refundLimit)
            ->pluck('id');
        $counts = ['processed' => 0, 'failed' => 0, 'payments' => 0, 'refunds' => 0];

        foreach ($paymentIds as $id) {
            $this->attempt('payment', (int) $id, $counts);
        }
        foreach ($refundIds as $id) {
            $this->attempt('refund', (int) $id, $counts);
        }

        return $counts;
    }

    private function attempt(string $type, int $id, array &$counts): void
    {
        try {
            $handled = Cache::lock("commercial:reconcile:{$type}:{$id}", 120)->get(
                fn (): bool => $type === 'payment' ? $this->payment($id) : $this->refund($id),
            );
            if ($handled === true) {
                $counts['processed']++;
                $counts[$type.'s']++;
            }
        } catch (Throwable) {
            $counts['failed']++;
        }
    }

    private function payment(int $id): bool
    {
        $payment = CommercialPayment::query()->whereKey($id)->whereNotNull('provider_payment_id')->first();
        if ($payment === null) {
            return false;
        }
        $result = $this->gateway->getPayment((string) $payment->provider_payment_id);
        $this->webhookProcessor->processAuthoritativePayment(
            $this->notification('payment.reconciliation', $result->id, $result->status),
            '127.0.0.1',
            $result,
        );

        return true;
    }

    private function refund(int $id): bool
    {
        $refund = CommercialRefund::query()->whereKey($id)->whereNotNull('provider_refund_id')->first();
        if ($refund === null) {
            return false;
        }
        $result = $this->gateway->getRefund((string) $refund->provider_refund_id);
        $payment = $this->gateway->getPayment($result->paymentId);
        $this->webhookProcessor->processAuthoritativeRefund(
            $this->notification('refund.reconciliation', $result->id, $result->status),
            '127.0.0.1',
            $result,
            $payment,
        );

        return true;
    }

    private function notification(string $event, string $id, string $status): YooKassaWebhookNotification
    {
        $runId = bin2hex(random_bytes(16));

        return new YooKassaWebhookNotification(
            $event,
            $id,
            $status,
            [
                'type' => 'reconciliation',
                'run_id' => $runId,
                'event' => $event,
                'object' => ['id' => $id, 'status' => $status],
            ],
        );
    }
}

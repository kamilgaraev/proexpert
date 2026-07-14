<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $paymentIds = CommercialPayment::query()
            ->whereNotNull('provider_payment_id')
            ->where(function ($query): void {
                $query->whereIn('provider_status', ['pending', 'waiting_for_capture', 'unknown'])
                    ->orWhere('reconciliation_required', true);
            })
            ->orderByRaw('COALESCE(last_reconciled_at, created_at)')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $remaining = max(0, $limit - $paymentIds->count());
        $refundIds = $remaining === 0 ? collect() : CommercialRefund::query()
            ->whereNotNull('provider_refund_id')
            ->where(function ($query): void {
                $query->whereIn('provider_status', ['created', 'pending', 'unknown'])
                    ->orWhere('reconciliation_required', true);
            })
            ->orderByRaw('COALESCE(last_reconciled_at, created_at)')
            ->orderBy('id')
            ->limit($remaining)
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
        $payment = DB::transaction(fn (): ?CommercialPayment => CommercialPayment::query()
            ->whereKey($id)->whereNotNull('provider_payment_id')->lockForUpdate()->first(), 3);
        if ($payment === null) {
            return false;
        }
        $result = $this->gateway->getPayment((string) $payment->provider_payment_id);
        $event = match ($result->status) {
            'succeeded' => 'payment.succeeded',
            'canceled' => 'payment.canceled',
            'waiting_for_capture' => 'payment.waiting_for_capture',
            default => null,
        };
        if ($event !== null) {
            $this->webhookProcessor->process($this->notification($event, $result->id, $result->status), '127.0.0.1');
        }
        CommercialPayment::query()->whereKey($id)->update([
            'provider_status' => $result->status,
            'safe_response' => $result->safeResponse,
            'last_reconciled_at' => now(),
            'reconciliation_required' => ! in_array($result->status, ['succeeded', 'canceled'], true),
        ]);

        return true;
    }

    private function refund(int $id): bool
    {
        $refund = DB::transaction(fn (): ?CommercialRefund => CommercialRefund::query()
            ->whereKey($id)->whereNotNull('provider_refund_id')->lockForUpdate()->first(), 3);
        if ($refund === null) {
            return false;
        }
        $result = $this->gateway->getRefund((string) $refund->provider_refund_id);
        if ($result->status === 'succeeded') {
            $this->webhookProcessor->process(
                $this->notification('refund.succeeded', $result->id, $result->status),
                '127.0.0.1',
            );
        }
        CommercialRefund::query()->whereKey($id)->update([
            'provider_status' => $result->status,
            'safe_response' => $result->safeResponse,
            'last_reconciled_at' => now(),
            'reconciliation_required' => ! in_array($result->status, ['succeeded', 'canceled'], true),
        ]);

        return true;
    }

    private function notification(string $event, string $id, string $status): YooKassaWebhookNotification
    {
        return new YooKassaWebhookNotification(
            $event,
            $id,
            $status,
            ['type' => 'reconciliation', 'event' => $event, 'object' => ['id' => $id, 'status' => $status]],
        );
    }
}

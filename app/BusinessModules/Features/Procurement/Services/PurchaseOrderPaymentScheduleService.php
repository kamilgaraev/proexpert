<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentScheduleSynchronizationService;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final readonly class PurchaseOrderPaymentScheduleService
{
    public function __construct(
        private PurchaseOrderSettlementService $settlement,
        private PaymentScheduleSynchronizationService $synchronization,
    ) {}

    public function synchronize(PurchaseOrder $order): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Purchase settlement requires an owner transaction.');
        }
        $order = PurchaseOrder::query()->where('organization_id', $order->organization_id)
            ->lockForUpdate()->findOrFail($order->id);
        $documents = PaymentDocument::query()
            ->where('organization_id', $order->organization_id)
            ->where('schedule_source', 'procurement')
            ->whereNotIn('status', [PaymentDocumentStatus::CANCELLED->value, PaymentDocumentStatus::REJECTED->value])
            ->where(fn ($query) => $query->where('metadata->purchase_order_id', $order->id)
                ->orWhere('metadata->purchase_order_id', (string) $order->id))
            ->orderBy('id')->lockForUpdate()->get();
        if ($documents->isEmpty()) {
            return;
        }
        if ($documents->count() !== 1) {
            throw new DomainException(trans_message('payments.schedule.source_conflict'));
        }
        try {
            $parts = $this->settlement->plan($order);
        } catch (InvalidArgumentException $exception) {
            throw new DomainException(trans_message('procurement.settlement_inconsistent'), 0, $exception);
        }
        if ($parts === null) {
            throw new DomainException(trans_message('procurement.settlement_inconsistent'));
        }
        foreach ($documents as $document) {
            if ($document->currency !== $order->currency) {
                throw new DomainException(trans_message('payments.schedule.source_conflict'));
            }
            $this->synchronization->synchronize($document->id, $order->organization_id, 'procurement', $parts);
        }
    }
}

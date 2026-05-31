<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class PurchaseOrderPaymentGateService
{
    public function summary(PurchaseOrder $order): array
    {
        $paidAmount = $this->paidAmount($order);
        $receivedAmount = $this->receivedAmount($order);
        $availableAmount = max(round($paidAmount - $receivedAmount, 2), 0.0);
        $canReceive = $availableAmount > 0.0001 || (float) $order->total_amount <= 0.0001;
        $blocker = null;

        if (! $canReceive && $order->status->canReceiveMaterials()) {
            $blocker = $paidAmount <= 0.0001
                ? trans_message('procurement.purchase_orders.payment_required_before_receipt')
                : trans_message('procurement.purchase_orders.payment_not_enough_for_receipt');
        }

        return [
            'paid_amount' => round($paidAmount, 2),
            'received_amount' => round($receivedAmount, 2),
            'available_paid_amount' => $availableAmount,
            'order_amount' => round((float) $order->total_amount, 2),
            'can_receive_materials' => $canReceive,
            'blocker' => $blocker,
            'documents_count' => $this->linkedDocumentsQuery($order)->count(),
        ];
    }

    public function assertCanReceive(PurchaseOrder $order, array $items): void
    {
        $incomingAmount = $this->incomingReceiptAmount($items);

        if ($incomingAmount <= 0.0001) {
            return;
        }

        $paidAmount = $this->paidAmount($order);
        $requiredAmount = round($this->receivedAmount($order) + $incomingAmount, 2);

        if ($paidAmount <= 0.0001) {
            throw new \DomainException(trans_message('procurement.purchase_orders.payment_required_before_receipt'));
        }

        if ($paidAmount + 0.0001 < $requiredAmount) {
            throw new \DomainException(trans_message('procurement.purchase_orders.payment_not_enough_for_receipt'));
        }
    }

    private function paidAmount(PurchaseOrder $order): float
    {
        return $this->linkedDocumentsQuery($order)
            ->get()
            ->sum(fn (PaymentDocument $document): float => $this->paidDocumentAmount($document));
    }

    private function paidDocumentAmount(PaymentDocument $document): float
    {
        $paidAmount = (float) $document->paid_amount;

        if ($document->status === PaymentDocumentStatus::PAID && $paidAmount <= 0.0001) {
            return (float) $document->amount;
        }

        return $paidAmount;
    }

    private function receivedAmount(PurchaseOrder $order): float
    {
        return (float) DB::table('purchase_receipt_lines')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_lines.purchase_receipt_id')
            ->where('purchase_receipts.organization_id', $order->organization_id)
            ->where('purchase_receipts.purchase_order_id', $order->id)
            ->whereNull('purchase_receipts.deleted_at')
            ->sum('purchase_receipt_lines.total_amount');
    }

    private function incomingReceiptAmount(array $items): float
    {
        return (float) collect($items)
            ->sum(static fn (array $item): float => round(
                (float) $item['quantity_received'] * (float) $item['price'],
                2
            ));
    }

    private function linkedDocumentsQuery(PurchaseOrder $order): Builder
    {
        return PaymentDocument::query()
            ->where('organization_id', $order->organization_id)
            ->where('direction', InvoiceDirection::OUTGOING->value)
            ->whereNotIn('status', [
                PaymentDocumentStatus::REJECTED->value,
                PaymentDocumentStatus::CANCELLED->value,
            ])
            ->where(function (Builder $query) use ($order): void {
                $query
                    ->where('metadata->purchase_order_id', $order->id)
                    ->orWhere('metadata->purchase_order_id', (string) $order->id);

                if ($order->contract_id !== null) {
                    $query->orWhere(function (Builder $contractQuery) use ($order): void {
                        $contractQuery
                            ->where('source_type', Contract::class)
                            ->where('source_id', $order->contract_id)
                            ->where('invoice_type', InvoiceType::MATERIAL_PURCHASE->value);
                    });
                }
            });
    }
}

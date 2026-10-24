<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Support\SupplierPaymentSchedule;

use App\BusinessModules\Features\Procurement\Exceptions\PurchaseReceiptPaymentException;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Procurement\DTOs\PurchaseOrderPaymentReadContext;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class PurchaseOrderPaymentGateService
{
    public function batchForListSerialization(Collection $orders): PurchaseOrderPaymentReadContext
    {
        $orders = $orders
            ->filter(static fn (mixed $order): bool => $order instanceof PurchaseOrder
                && $order->exists
                && (int) $order->organization_id > 0
                && (int) $order->getKey() > 0)
            ->unique(static fn (PurchaseOrder $order): string => $order->organization_id.':'.$order->getKey())
            ->values();
        $documents = [];
        $receivedAmounts = [];

        foreach ($orders as $order) {
            $key = $order->organization_id.':'.$order->getKey();
            $documents[$key] = [];
            $receivedAmounts[$key] = 0.0;
        }

        $versions = new \Illuminate\Database\Eloquent\Collection($orders->filter(
            static fn (PurchaseOrder $order): bool => $order->accepted_supplier_proposal_id !== null
                && $order->accepted_supplier_proposal_version_id !== null
        )->all());
        $versions->loadMissing('acceptedSupplierProposalVersion');

        foreach ($orders->groupBy('organization_id') as $organizationId => $organizationOrders) {
            foreach ($organizationOrders->chunk(50) as $chunk) {
                $documentsQuery = null;
                foreach ($chunk as $order) {
                    $branch = $this->linkedDocumentsQuery($order)
                        ->select('payment_documents.*')
                        ->selectRaw('CAST(? AS BIGINT) AS list_read_order_id', [$order->getKey()])
                        ->toBase();
                    $documentsQuery = $documentsQuery === null ? $branch : $documentsQuery->unionAll($branch);
                }

                if ($documentsQuery !== null) {
                    $models = PaymentDocument::query()->hydrate($documentsQuery->get()->all());
                    foreach ($models as $document) {
                        $orderId = (int) $document->getAttribute('list_read_order_id');
                        $document->offsetUnset('list_read_order_id');
                        $documents[$organizationId.':'.$orderId][] = $document;
                    }
                }

                $received = DB::table('purchase_receipt_lines')
                    ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_lines.purchase_receipt_id')
                    ->where('purchase_receipts.organization_id', $organizationId)
                    ->whereIn('purchase_receipts.purchase_order_id', $chunk->map(
                        static fn (PurchaseOrder $order): int => (int) $order->getKey()
                    )->all())
                    ->where('purchase_receipts.status', 'posted')
                    ->whereNull('purchase_receipts.deleted_at')
                    ->whereNull('purchase_receipt_lines.reversed_at')
                    ->groupBy('purchase_receipts.organization_id', 'purchase_receipts.purchase_order_id')
                    ->select('purchase_receipts.organization_id', 'purchase_receipts.purchase_order_id')
                    ->selectRaw('SUM(purchase_receipt_lines.total_amount) AS received_amount')
                    ->get();

                foreach ($received as $row) {
                    $receivedAmounts[$row->organization_id.':'.$row->purchase_order_id] = (float) $row->received_amount;
                }
            }
        }

        return new PurchaseOrderPaymentReadContext($documents, $receivedAmounts);
    }

    public function summary(PurchaseOrder $order, ?PurchaseOrderPaymentReadContext $context = null): array
    {
        $documents = $context?->hasOrder($order) ? $context->documentsFor($order) : null;
        $paidAmount = $documents === null
            ? $this->paidAmount($order)
            : $documents->sum(fn (PaymentDocument $document): float => $this->paidDocumentAmount($document));
        $receivedAmount = $documents === null ? $this->receivedAmount($order) : $context->receivedAmountFor($order);
        $availableAmount = max(round($paidAmount - $receivedAmount, 2), 0.0);
        $requiredAdvance = $this->requiredAdvanceAmount($order);
        $receiptLimit = $requiredAdvance === null
            ? $availableAmount
            : ($paidAmount + 0.0001 >= $requiredAdvance ? null : 0.0);
        $canReceive = $receiptLimit === null || $receiptLimit > 0.0001 || (float) $order->total_amount <= 0.0001;
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
            'receipt_amount_limit' => $receiptLimit,
            'required_advance_amount' => $requiredAdvance,
            'order_amount' => round((float) $order->total_amount, 2),
            'can_receive_materials' => $canReceive,
            'blocker' => $blocker,
            'documents_count' => $documents?->count() ?? $this->linkedDocumentsQuery($order)->count(),
        ];
    }

    /**
     * @return Collection<int, PaymentDocument>
     */
    public function linkedDocuments(PurchaseOrder $order, ?PurchaseOrderPaymentReadContext $context = null): Collection
    {
        if ($context?->hasOrder($order)) {
            return $context->documentsFor($order)->sort(static function (PaymentDocument $left, PaymentDocument $right): int {
                $leftDate = $left->getRawOriginal('document_date');
                $rightDate = $right->getRawOriginal('document_date');
                $dateOrder = $leftDate === null || $rightDate === null
                    ? ($leftDate === null ? 0 : 1) <=> ($rightDate === null ? 0 : 1)
                    : strcmp((string) $rightDate, (string) $leftDate);

                return $dateOrder ?: $right->getKey() <=> $left->getKey();
            })->values();
        }

        return $this->linkedDocumentsQuery($order)
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->get();
    }

    public function assertCanReceive(PurchaseOrder $order, array $items): void
    {
        $incomingAmount = $this->incomingReceiptAmount($items);

        if ($incomingAmount <= 0.0001) {
            return;
        }

        $paidAmount = $this->paidAmount($order);
        $requiredAdvance = $this->requiredAdvanceAmount($order);
        $requiredAmount = $requiredAdvance ?? round($this->receivedAmount($order) + $incomingAmount, 2);

        if ($paidAmount + 0.0001 >= $requiredAmount) {
            return;
        }

        if ($paidAmount <= 0.0001) {
            throw new PurchaseReceiptPaymentException(
                trans_message('procurement.purchase_orders.payment_required_before_receipt')
            );
        }

        if ($paidAmount + 0.0001 < $requiredAmount) {
            throw new PurchaseReceiptPaymentException(
                trans_message('procurement.purchase_orders.payment_not_enough_for_receipt')
            );
        }
    }

    public function requiredAdvanceAmount(PurchaseOrder $order): ?float
    {
        if ($order->accepted_supplier_proposal_version_id === null || $order->accepted_supplier_proposal_id === null) {
            return null;
        }

        $order->loadMissing('acceptedSupplierProposalVersion');
        $version = $order->getRelation('acceptedSupplierProposalVersion');
        if (! $version instanceof SupplierProposalVersion
            || (int) $version->id !== (int) $order->accepted_supplier_proposal_version_id
            || (int) $version->organization_id !== (int) $order->organization_id
            || (int) $version->supplier_proposal_id !== (int) $order->accepted_supplier_proposal_id
            || $version->integrity_status !== 'verified') {
            return null;
        }

        $schedule = SupplierPaymentSchedule::normalize($version->commercial_snapshot['payment_schedule'] ?? null);

        return $schedule === null ? null : round((float) $order->total_amount * $schedule['advance_percent'] / 100, 2);
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
            ->where('purchase_receipts.status', 'posted')
            ->whereNull('purchase_receipts.deleted_at')
            ->whereNull('purchase_receipt_lines.reversed_at')
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

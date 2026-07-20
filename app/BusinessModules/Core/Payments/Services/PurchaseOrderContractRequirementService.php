<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use DomainException;

final class PurchaseOrderContractRequirementService
{
    private array $orders = [];

    public function preload(iterable $documents): void
    {
        $documents = collect($documents)->filter(static fn ($document): bool => $document instanceof PaymentDocument);
        $orderIds = $documents->map(function (PaymentDocument $document): ?int {
            $metadata = is_array($document->metadata) ? $document->metadata : [];
            $id = filter_var($metadata['purchase_order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return $id === false ? null : $id;
        })->filter()->unique()->values();
        $ordersById = PurchaseOrder::query()
            ->whereIn('id', $orderIds)
            ->with('contract:id,status')
            ->get()
            ->keyBy('id');
        $contractIds = $documents
            ->filter(static fn (PaymentDocument $document): bool => $document->source_type === Contract::class && $document->source_id !== null)
            ->pluck('source_id')
            ->unique()
            ->values();
        $ordersByContract = PurchaseOrder::query()
            ->whereIn('contract_id', $contractIds)
            ->with('contract:id,status')
            ->get()
            ->groupBy('contract_id');

        foreach ($documents as $document) {
            $metadata = is_array($document->metadata) ? $document->metadata : [];
            $orderId = filter_var($metadata['purchase_order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $order = $orderId === false ? null : $ordersById->get($orderId);
            if ($order instanceof PurchaseOrder && (int) $order->organization_id === (int) $document->organization_id) {
                $this->orders[(int) $document->id] = $order;
                continue;
            }
            $matches = $document->source_type === Contract::class && $document->source_id !== null
                ? $ordersByContract->get($document->source_id, collect())->where('organization_id', $document->organization_id)
                : collect();
            $this->orders[(int) $document->id] = $matches->count() === 1 ? $matches->first() : null;
        }
    }

    public function assertPaymentAllowed(PaymentDocument $document): void
    {
        $order = $this->purchaseOrder($document, true);
        if ($order instanceof PurchaseOrder
            && $order->contract?->status !== ContractStatusEnum::ACTIVE) {
            throw new DomainException(trans_message('payments.validation.contract_required_not_active'));
        }
    }

    public function blocker(PaymentDocument $document): ?string
    {
        $order = $this->purchaseOrder($document);
        if (! $order instanceof PurchaseOrder) {
            return null;
        }

        return $order->contract?->status === ContractStatusEnum::ACTIVE
            ? null
            : 'contract_required_not_active';
    }

    public function continuationAction(PaymentDocument $document): ?array
    {
        $order = $this->purchaseOrder($document);
        if (! $order instanceof PurchaseOrder || $order->contract_id !== null) {
            return null;
        }
        if (! in_array($order->status, [
            PurchaseOrderStatusEnum::CONFIRMED,
            PurchaseOrderStatusEnum::IN_DELIVERY,
            PurchaseOrderStatusEnum::PARTIALLY_DELIVERED,
            PurchaseOrderStatusEnum::DELIVERED,
        ], true)) {
            return null;
        }

        return [
            'type' => 'continue_contract_creation',
            'purchase_order_id' => (int) $order->id,
        ];
    }

    private function purchaseOrder(PaymentDocument $document, bool $lock = false): ?PurchaseOrder
    {
        if (! $lock && array_key_exists((int) $document->id, $this->orders)) {
            return $this->orders[(int) $document->id];
        }
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $orderId = filter_var($metadata['purchase_order_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($orderId === false) {
            return $this->cacheOrder($document, $this->orderByContractSource($document, $lock), $lock);
        }

        $order = PurchaseOrder::query()
            ->whereKey($orderId)
            ->where('organization_id', $document->organization_id)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->first();
        if ($order instanceof PurchaseOrder) {
            return $this->cacheOrder($document, $this->loadContract($order, $lock), $lock);
        }

        return $this->cacheOrder($document, $this->orderByContractSource($document, $lock), $lock);
    }

    private function orderByContractSource(PaymentDocument $document, bool $lock): ?PurchaseOrder
    {
        if ($document->source_type !== Contract::class || $document->source_id === null) {
            return null;
        }
        $orders = PurchaseOrder::query()
            ->where('organization_id', $document->organization_id)
            ->where('contract_id', $document->source_id)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->limit(2)
            ->get();

        return $orders->count() === 1 ? $this->loadContract($orders->first(), $lock) : null;
    }

    private function loadContract(PurchaseOrder $order, bool $lock): PurchaseOrder
    {
        if ($order->contract_id !== null) {
            $order->setRelation('contract', Contract::query()
                ->whereKey($order->contract_id)
                ->when($lock, static fn ($query) => $query->lockForUpdate())
                ->first(['id', 'status']));
        }

        return $order;
    }

    private function cacheOrder(PaymentDocument $document, ?PurchaseOrder $order, bool $lock): ?PurchaseOrder
    {
        if (! $lock) {
            $this->orders[(int) $document->id] = $order;
        }

        return $order;
    }
}

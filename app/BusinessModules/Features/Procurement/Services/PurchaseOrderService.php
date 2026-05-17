<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\Models\Contract;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use function trans_message;

class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderPdfService $pdfService,
        private readonly SupplierPartyService $supplierPartyService,
        private readonly ProcurementAuditService $auditService,
        private readonly ProcurementLifecycleService $lifecycleService,
        private readonly ProjectMaterialDeliveryService $deliveryService
    ) {
    }

    public function create(PurchaseRequest $request, int $supplierId, array $data, ?int $actorId = null): PurchaseOrder
    {
        $this->lifecycleService->assertCanCreateSupplierRequest($request);

        if ($request->purchaseOrders()->exists()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.already_exists_for_request'));
        }

        $supplier = Supplier::query()
            ->where('organization_id', $request->organization_id)
            ->where('id', $supplierId)
            ->where('is_active', true)
            ->first();

        if (!$supplier) {
            throw new \DomainException(trans_message('procurement.purchase_orders.supplier_not_found'));
        }

        $supplierParty = $this->supplierPartyService->resolveRegisteredParty($request->organization_id, $supplierId);
        $supplierSnapshot = $this->supplierPartyService->snapshotForDocument($supplierParty);

        DB::beginTransaction();

        try {
            $orderNumber = $this->generateOrderNumber($request->organization_id);

            $order = PurchaseOrder::create([
                'organization_id' => $request->organization_id,
                'purchase_request_id' => $request->id,
                'supplier_id' => $supplierId,
                'supplier_party_id' => $supplierParty->id,
                'supplier_snapshot' => $supplierSnapshot,
                'order_number' => $orderNumber,
                'order_date' => $data['order_date'] ?? now(),
                'status' => PurchaseOrderStatusEnum::DRAFT,
                'total_amount' => $data['total_amount'] ?? 0,
                'currency' => $data['currency'] ?? 'RUB',
                'delivery_date' => $data['delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $siteRequest = $request->siteRequest;
            if ($siteRequest && ($siteRequest->material_id || $siteRequest->material_name)) {
                $order->items()->create([
                    'material_id' => $siteRequest->material_id,
                    'material_name' => $siteRequest->material_name,
                    'quantity' => $siteRequest->material_quantity ?? 1,
                    'unit' => $siteRequest->material_unit ?? 'шт.',
                    'unit_price' => 0,
                    'total_price' => 0,
                ]);
            }

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::PURCHASE_ORDER_CREATED->value,
                $order,
                (int) $order->organization_id,
                $actorId,
                $order->supplier_party_id,
                [
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'purchase_request_number' => $request->request_number,
                    'supplier_name' => $this->supplierName($supplierSnapshot),
                    'supplier_snapshot' => $supplierSnapshot,
                    'total_amount' => (float) $order->total_amount,
                    'currency' => $order->currency,
                    'pricing_source' => $order->pricing_source,
                ]
            );

            $this->syncDeliveryFromOrder($order, $request, $actorId);

            DB::commit();

            $this->invalidateCache($request->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseOrderCreated($order));

            return $order->fresh(['supplier', 'supplierParty', 'purchaseRequest', 'items']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function sendToSupplier(PurchaseOrder $order): PurchaseOrder
    {
        if (!$order->canBeSent()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_send'));
        }

        if (!$order->supplier || !$order->supplier->email) {
            throw new \DomainException(trans_message('procurement.purchase_orders.supplier_email_missing'));
        }

        DB::beginTransaction();

        try {
            $pdfPath = $this->pdfService->store($order);
            $temporaryUrl = $this->pdfService->getTemporaryUrl($order, $pdfPath, 1440);

            \Illuminate\Support\Facades\Mail::to($order->supplier->email)
                ->queue(new \App\BusinessModules\Features\Procurement\Mail\PurchaseOrderSentMail($order, $temporaryUrl));

            $order->update([
                'status' => PurchaseOrderStatusEnum::SENT,
                'sent_at' => now(),
                'metadata' => array_merge($order->metadata ?? [], [
                    'pdf_path' => $pdfPath,
                    'pdf_temporary_url' => $temporaryUrl,
                    'email_sent_to' => $order->supplier->email,
                    'sent_by_user_id' => auth()->id(),
                ]),
            ]);

            DB::commit();

            $this->invalidateCache($order->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseOrderSent($order));

            return $order->fresh(['items', 'supplier', 'supplierParty', 'purchaseRequest', 'contract']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function confirm(PurchaseOrder $order, array $proposalData): PurchaseOrder
    {
        if (!$order->canBeConfirmed()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_confirm'));
        }

        DB::beginTransaction();

        try {
            $order->update([
                'status' => PurchaseOrderStatusEnum::CONFIRMED,
                'confirmed_at' => now(),
                'total_amount' => $proposalData['total_amount'] ?? $order->total_amount,
            ]);

            $this->syncDeliveryFromOrder($order, $order->purchaseRequest);

            if (isset($proposalData['items'])) {
                app(SupplierProposalService::class)->createFromOrder($order, $proposalData);
            }

            DB::commit();

            $this->invalidateCache($order->organization_id);

            return $order->fresh(['supplier', 'supplierParty', 'proposals', 'items', 'purchaseRequest']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function receiveMaterials(
        PurchaseOrder $order,
        int $warehouseId,
        array $items,
        int $userId,
        array $receiptData = []
    ): PurchaseOrder {
        $this->lifecycleService->assertCanReceiveMaterials($order, $items);

        $warehouse = OrganizationWarehouse::query()
            ->where('organization_id', $order->organization_id)
            ->where('id', $warehouseId)
            ->where('is_active', true)
            ->first();

        if (!$warehouse) {
            throw new \DomainException(trans_message('procurement.purchase_orders.warehouse_not_found'));
        }

        $orderItems = $this->resolveOrderItems($order, $items);

        DB::beginTransaction();

        try {
            $receipt = $order->receipts()->create([
                'organization_id' => $order->organization_id,
                'warehouse_id' => $warehouse->id,
                'received_by_user_id' => $userId,
                'receipt_number' => $this->generateReceiptNumber($order->organization_id),
                'receipt_date' => $receiptData['receipt_date'] ?? now()->toDateString(),
                'notes' => $receiptData['notes'] ?? null,
                'metadata' => $receiptData['metadata'] ?? null,
            ]);

            foreach ($items as $item) {
                $quantity = (float) $item['quantity_received'];
                $price = (float) $item['price'];

                $receipt->lines()->create([
                    'purchase_order_item_id' => (int) $item['item_id'],
                    'quantity_received' => $quantity,
                    'price' => $price,
                    'total_amount' => round($quantity * $price, 2),
                    'metadata' => $item['metadata'] ?? null,
                ]);
            }

            event(new \App\BusinessModules\Features\Procurement\Events\MaterialReceivedFromSupplier(
                $order,
                $warehouse->id,
                $items,
                $userId
            ));

            $order->update([
                'status' => $this->lifecycleService->resolveOrderReceiptStatus($order),
            ]);

            $receipt->loadMissing('lines');
            $order->loadMissing('items');

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::MATERIALS_RECEIVED->value,
                $order,
                (int) $order->organization_id,
                $userId,
                $order->supplier_party_id,
                [
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'receipt_number' => $receipt->receipt_number,
                    'receipt_date' => $receipt->receipt_date?->format('Y-m-d'),
                    'warehouse_id' => $warehouse->id,
                    'warehouse_name' => $warehouse->name,
                    'supplier_name' => $this->supplierName(is_array($order->supplier_snapshot) ? $order->supplier_snapshot : []),
                    'items_count' => count($items),
                    'total_received_amount' => $receipt->lines->sum('total_amount'),
                    'items' => $this->receivedItemsPayload($order, $items),
                    'notes' => $receipt->notes,
                ]
            );

            DB::commit();

            $this->invalidateCache($order->organization_id);

            Log::info('procurement.materials_received', [
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'items_count' => $orderItems->count(),
                'user_id' => $userId,
            ]);

            return $order->fresh([
                'items.receiptLines',
                'supplier',
                'externalSupplierContact',
                'supplierParty',
                'purchaseRequest',
                'receipts.lines',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('procurement.materials_receive_failed', [
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function markInDelivery(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatusEnum::CONFIRMED) {
            throw new \DomainException(trans_message('procurement.purchase_orders.invalid_status_for_delivery'));
        }

        DB::beginTransaction();

        try {
            $order->update([
                'status' => PurchaseOrderStatusEnum::IN_DELIVERY,
            ]);

            $this->markDeliveryInTransit($order);

            DB::commit();

            $this->invalidateCache($order->organization_id);

            Log::info('procurement.purchase_order.in_delivery', [
                'purchase_order_id' => $order->id,
            ]);

            return $order->fresh(['items', 'supplier', 'supplierParty', 'purchaseRequest']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createContractFromOrder(PurchaseOrder $order): Contract
    {
        if ($order->hasContract()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.contract_already_exists'));
        }

        return app(PurchaseContractService::class)->createFromOrder($order);
    }

    private function resolveOrderItems(PurchaseOrder $order, array $items): Collection
    {
        $orderItemIds = collect($items)
            ->pluck('item_id')
            ->map(static fn ($value) => (int) $value)
            ->unique()
            ->values();

        $orderItems = $order->items()
            ->whereIn('id', $orderItemIds)
            ->get()
            ->keyBy('id');

        if ($orderItems->count() !== $orderItemIds->count()) {
            throw new \DomainException(trans_message('procurement.purchase_orders.item_not_found'));
        }

        return $orderItems->values();
    }

    private function generateReceiptNumber(int $organizationId): string
    {
        $year = date('Y');
        $month = date('m');

        $lastReceipt = PurchaseReceipt::query()
            ->where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;
        if ($lastReceipt && preg_match('/(\d+)$/', $lastReceipt->receipt_number, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        return sprintf('PR-%s%s-%04d', $year, $month, $nextNumber);
    }

    private function generateOrderNumber(int $organizationId): string
    {
        $year = date('Y');
        $month = date('m');

        $lastOrder = PurchaseOrder::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;
        if ($lastOrder && preg_match('/(\d+)$/', $lastOrder->order_number, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        return sprintf('ЗП-%s%s-%04d', $year, $month, $nextNumber);
    }

    private function invalidateCache(int $organizationId): void
    {
        Cache::forget("procurement_purchase_orders_{$organizationId}");
    }

    private function supplierName(array $supplierSnapshot): ?string
    {
        $name = $supplierSnapshot['display_name'] ?? null;

        return $name === null ? null : (string) $name;
    }

    private function receivedItemsPayload(PurchaseOrder $order, array $items): array
    {
        $itemsById = $order->items->keyBy('id');

        return collect($items)
            ->map(static function (array $item) use ($itemsById): array {
                $orderItem = $itemsById->get((int) $item['item_id']);

                return [
                    'item_id' => (int) $item['item_id'],
                    'material_name' => $orderItem?->material_name,
                    'quantity_received' => (float) $item['quantity_received'],
                    'unit' => $orderItem?->unit,
                    'price' => (float) $item['price'],
                    'total_amount' => round((float) $item['quantity_received'] * (float) $item['price'], 2),
                ];
            })
            ->values()
            ->all();
    }

    private function syncDeliveryFromOrder(PurchaseOrder $order, PurchaseRequest $request, ?int $actorId = null): void
    {
        if (!$request->site_request_id) {
            return;
        }

        $delivery = ProjectMaterialDelivery::query()
            ->where('organization_id', $order->organization_id)
            ->where(function ($query) use ($request): void {
                $query->where('purchase_request_id', $request->id)
                    ->orWhere('site_request_id', $request->site_request_id);
            })
            ->first();

        $actor = $this->resolveDeliveryActor($request, $actorId);

        if (!$delivery || !$actor) {
            return;
        }

        $this->deliveryService->linkPurchaseOrder($delivery, $order, $actor);
    }

    private function markDeliveryInTransit(PurchaseOrder $order): void
    {
        $request = $order->purchaseRequest;

        if (!$request || !$request->site_request_id) {
            return;
        }

        $delivery = ProjectMaterialDelivery::query()
            ->where('organization_id', $order->organization_id)
            ->where('purchase_order_id', $order->id)
            ->first();

        $actor = $this->resolveDeliveryActor($request);

        if (!$delivery || !$actor || $delivery->status?->canBeReceived()) {
            return;
        }

        $this->deliveryService->ship($delivery, $actor, [
            'quantity' => max((float) $delivery->requested_quantity, (float) $delivery->reserved_quantity),
            'notes' => trans_message('basic_warehouse.project_material_deliveries.in_transit_from_purchase_order'),
        ]);
    }

    private function resolveDeliveryActor(PurchaseRequest $request, ?int $actorId = null): ?User
    {
        $userId = $actorId ?: auth()->id() ?: $request->assigned_to ?: $request->siteRequest?->user_id;

        return $userId ? User::query()->find($userId) : null;
    }
}
